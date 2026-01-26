<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AutomationWorkflow;
use App\Models\Review;
use App\Models\ReviewResponse;
use App\Models\User;
use App\Services\AIResponse\AIResponseService;
use App\Services\Sentiment\SentimentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIAutomationService
{
    protected string $baseUrl;
    protected string $model;

    public function __construct(
        protected AIResponseService $aiResponseService,
        protected SentimentService $sentimentService
    ) {
        $this->baseUrl = config('openrouter.base_url');
        $this->model = config('openrouter.model');
    }

    /**
     * Intelligently decide whether to auto-respond to a review
     */
    public function shouldAutoRespond(Review $review, AutomationWorkflow $workflow): array
    {
        $apiKey = config('openrouter.api_key');

        if (empty($apiKey)) {
            return [
                'should_respond' => true, // Default to allowing response creation
                'reason' => 'AI service not configured - creating draft response',
                'confidence' => 0.5,
                'suggested_tone' => 'professional',
                'urgency' => 'medium',
                'complexity' => 'moderate',
                'risk_factors' => ['ai_unavailable'],
            ];
        }

        $aiConfig = $workflow->ai_config ?? [];
        $safetyLevel = $aiConfig['safety_level'] ?? 'medium'; // low, medium, high
        $requireApproval = $aiConfig['require_approval'] ?? true;

        // Get review context
        $reviewContent = $review->content ?? '';
        $rating = $review->rating;
        $platform = $review->platform;
        $locationName = $review->location->name;

        // Get sentiment if available
        $sentiment = $review->sentiment;
        $sentimentContext = '';
        if ($sentiment) {
            $sentimentContext = "Sentiment: {$sentiment->sentiment} (score: {$sentiment->sentiment_score})";
            if (!empty($sentiment->emotions)) {
                $topEmotions = collect($sentiment->emotions)->sortDesc()->take(3)->keys()->implode(', ');
                $sentimentContext .= "\nEmotions: {$topEmotions}";
            }
        }

        $prompt = $this->buildDecisionPrompt(
            $reviewContent,
            $rating,
            $platform,
            $locationName,
            $sentimentContext,
            $safetyLevel
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])->timeout(10)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an AI assistant that helps decide whether to automatically respond to customer reviews. Always respond with valid JSON.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,
                'max_tokens' => 300,
            ]);

            if (!$response->successful()) {
                Log::error('AI decision API error', [
                    'review_id' => $review->id,
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);
                
                // Fallback to allowing response creation
                return [
                    'should_respond' => true,
                    'reason' => 'AI service error - creating draft response',
                    'confidence' => 0.5,
                    'suggested_tone' => 'professional',
                    'urgency' => 'medium',
                    'complexity' => 'moderate',
                    'risk_factors' => ['ai_service_error'],
                ];
            }

            $content = $response->json('choices.0.message.content');
            $decision = $this->parseDecisionResponse($content);

            // Apply safety overrides
            if ($safetyLevel === 'high' && $decision['confidence'] < 0.8) {
                $decision['should_respond'] = false;
                $decision['reason'] = 'Low confidence - safety override';
                $decision['requires_approval'] = true;
            }

            if ($requireApproval && $decision['should_respond']) {
                $decision['requires_approval'] = true;
            }

            return $decision;

        } catch (\Exception $e) {
            Log::error('AI decision error', [
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);

            // Fallback to allowing response creation
            return [
                'should_respond' => true,
                'reason' => 'AI service unavailable - creating draft response',
                'confidence' => 0.5,
                'suggested_tone' => 'professional',
                'urgency' => 'medium',
                'complexity' => 'moderate',
                'risk_factors' => ['ai_unavailable'],
            ];
        }
    }

    /**
     * Generate an intelligent auto-response with safety checks
     */
    public function generateIntelligentResponse(
        Review $review,
        User $user,
        AutomationWorkflow $workflow
    ): array {
        $aiConfig = $workflow->ai_config ?? [];
        
        // First, check if we should respond
        $decision = $this->shouldAutoRespond($review, $workflow);
        
        if (!$decision['should_respond']) {
            return [
                'success' => false,
                'reason' => $decision['reason'],
                'decision' => $decision,
            ];
        }

        // Determine response parameters based on AI analysis
        $responseParams = $this->determineResponseParameters($review, $aiConfig);

        try {
            // Try to generate the response using existing AI service
            $result = $this->aiResponseService->generateResponse($review, $user, $responseParams);

            if (!$result) {
                // If AI generation fails, create a basic draft response
                $response = $review->response()->create([
                    'user_id' => $user->id,
                    'content' => '',
                    'status' => 'draft',
                    'ai_generated' => false,
                    'tone' => $responseParams['tone'] ?? 'professional',
                    'language' => 'en',
                    'rejection_reason' => 'AI generation failed - requires manual response',
                ]);

                return [
                    'success' => true,
                    'response' => $response,
                    'decision' => $decision,
                    'ai_failed' => true,
                    'auto_approved' => false,
                    'requires_manual_review' => true,
                ];
            }

            $response = $result['response'];

            // Apply safety checks
            $safetyCheck = $this->performSafetyCheck($response, $review);
            
            if (!$safetyCheck['is_safe']) {
                // Mark for manual review
                $response->update([
                    'status' => 'draft',
                    'rejection_reason' => 'AI Safety: ' . $safetyCheck['reason'],
                ]);

                return [
                    'success' => true,
                    'response' => $response,
                    'decision' => $decision,
                    'safety_check' => $safetyCheck,
                    'auto_approved' => false,
                    'requires_review' => true,
                ];
            }

            // Determine if auto-approval is allowed
            $autoApprove = $this->shouldAutoApprove($response, $review, $aiConfig, $decision);

            if ($autoApprove) {
                $response->update(['status' => 'approved']);
            }

            return [
                'success' => true,
                'response' => $response,
                'decision' => $decision,
                'safety_check' => $safetyCheck,
                'auto_approved' => $autoApprove,
                'parameters' => $responseParams,
            ];

        } catch (\Exception $e) {
            Log::error('Intelligent response generation failed', [
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);

            // Create a draft response for manual handling even if AI fails
            $response = $review->response()->create([
                'user_id' => $user->id,
                'content' => '',
                'status' => 'draft',
                'ai_generated' => false,
                'tone' => $responseParams['tone'] ?? 'professional',
                'language' => 'en',
                'rejection_reason' => 'AI generation error: ' . $e->getMessage(),
            ]);

            return [
                'success' => true,
                'response' => $response,
                'decision' => $decision,
                'ai_failed' => true,
                'auto_approved' => false,
                'requires_manual_review' => true,
            ];
        }
    }

    /**
     * Analyze review patterns and suggest workflow optimizations
     */
    public function analyzeAndOptimize(AutomationWorkflow $workflow): array
    {
        $apiKey = config('openrouter.api_key');

        if (empty($apiKey)) {
            return ['suggestions' => [], 'reason' => 'AI service not configured'];
        }

        // Get recent executions and their outcomes
        $recentExecutions = $workflow->executions()
            ->with(['logs'])
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        if ($recentExecutions->isEmpty()) {
            return ['suggestions' => [], 'reason' => 'Insufficient execution data'];
        }

        $analysisData = $this->prepareAnalysisData($workflow, $recentExecutions);
        $prompt = $this->buildOptimizationPrompt($analysisData);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an AI automation expert that analyzes workflow performance and suggests optimizations. Always respond with valid JSON.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.4,
                'max_tokens' => 800,
            ]);

            if (!$response->successful()) {
                return ['suggestions' => [], 'reason' => 'AI analysis service error'];
            }

            $content = $response->json('choices.0.message.content');
            return $this->parseOptimizationResponse($content);

        } catch (\Exception $e) {
            Log::error('Workflow optimization analysis failed', [
                'workflow_id' => $workflow->id,
                'error' => $e->getMessage(),
            ]);

            return ['suggestions' => [], 'reason' => 'Analysis failed'];
        }
    }

    protected function buildDecisionPrompt(
        string $reviewContent,
        ?int $rating,
        string $platform,
        string $locationName,
        string $sentimentContext,
        string $safetyLevel
    ): string {
        return <<<PROMPT
Analyze this customer review and decide whether it should receive an automated response.

Review Details:
- Business: {$locationName}
- Platform: {$platform}
- Rating: {$rating}/5 stars
- Content: "{$reviewContent}"
- {$sentimentContext}

Safety Level: {$safetyLevel}

Consider these factors:
1. Review sentiment and tone
2. Complexity of issues raised
3. Potential for controversy or escalation
4. Whether a generic response would be appropriate
5. Risk of automated response causing harm

Respond with JSON:
{
    "should_respond": boolean,
    "confidence": float (0-1),
    "reason": "explanation",
    "suggested_tone": "professional|friendly|apologetic|empathetic",
    "urgency": "low|medium|high",
    "complexity": "simple|moderate|complex",
    "risk_factors": ["factor1", "factor2"]
}
PROMPT;
    }

    protected function determineResponseParameters(Review $review, array $aiConfig): array
    {
        $params = [
            'use_sentiment_context' => true,
            'include_location_context' => true,
            'auto_detect_language' => true,
        ];

        // Determine tone based on rating and sentiment
        if ($review->rating <= 2) {
            $params['tone'] = 'apologetic';
        } elseif ($review->rating >= 4) {
            $params['tone'] = 'friendly';
        } else {
            $params['tone'] = 'professional';
        }

        // Apply AI config overrides
        if (isset($aiConfig['default_tone'])) {
            $params['tone'] = $aiConfig['default_tone'];
        }

        if (isset($aiConfig['max_length'])) {
            $params['max_length'] = $aiConfig['max_length'];
        }

        if (isset($aiConfig['brand_voice_id'])) {
            $params['brand_voice_id'] = $aiConfig['brand_voice_id'];
        }

        return $params;
    }

    protected function performSafetyCheck(ReviewResponse $response, Review $review): array
    {
        $content = strtolower($response->content);
        $reviewContent = strtolower($review->content ?? '');

        // Basic safety checks
        $riskWords = [
            'lawsuit', 'legal', 'lawyer', 'sue', 'court',
            'discrimination', 'harassment', 'abuse',
            'medical', 'health', 'injury', 'accident',
            'refund', 'money back', 'compensation',
        ];

        foreach ($riskWords as $word) {
            if (str_contains($content, $word) || str_contains($reviewContent, $word)) {
                return [
                    'is_safe' => false,
                    'reason' => "Contains sensitive keyword: {$word}",
                    'risk_level' => 'high',
                ];
            }
        }

        // Check for overly generic responses
        if (strlen($response->content) < 30) {
            return [
                'is_safe' => false,
                'reason' => 'Response too short/generic',
                'risk_level' => 'medium',
            ];
        }

        // Check for inappropriate tone mismatch
        if ($review->rating <= 2 && !str_contains($content, 'sorry') && !str_contains($content, 'apologize')) {
            return [
                'is_safe' => false,
                'reason' => 'Missing apology for negative review',
                'risk_level' => 'medium',
            ];
        }

        return [
            'is_safe' => true,
            'reason' => 'Passed all safety checks',
            'risk_level' => 'low',
        ];
    }

    protected function shouldAutoApprove(
        ReviewResponse $response,
        Review $review,
        array $aiConfig,
        array $decision
    ): bool {
        $autoApprovalEnabled = $aiConfig['auto_approval'] ?? false;
        
        if (!$autoApprovalEnabled) {
            return false;
        }

        $minConfidence = $aiConfig['auto_approval_confidence'] ?? 0.8;
        $maxRating = $aiConfig['auto_approval_max_rating'] ?? 3; // Only auto-approve up to 3-star reviews

        return $decision['confidence'] >= $minConfidence && 
               $review->rating <= $maxRating &&
               $decision['complexity'] === 'simple';
    }

    protected function parseDecisionResponse(string $content): array
    {
        $content = trim($content);
        
        // Extract JSON from response
        if (str_contains($content, '```')) {
            preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches);
            $content = $matches[1] ?? $content;
        }

        try {
            $decision = json_decode($content, true);
            
            if (!is_array($decision)) {
                throw new \Exception('Invalid JSON response');
            }

            // Ensure required fields
            return [
                'should_respond' => $decision['should_respond'] ?? false,
                'confidence' => $decision['confidence'] ?? 0.5,
                'reason' => $decision['reason'] ?? 'No reason provided',
                'suggested_tone' => $decision['suggested_tone'] ?? 'professional',
                'urgency' => $decision['urgency'] ?? 'medium',
                'complexity' => $decision['complexity'] ?? 'moderate',
                'risk_factors' => $decision['risk_factors'] ?? [],
            ];

        } catch (\Exception $e) {
            return [
                'should_respond' => false,
                'confidence' => 0.0,
                'reason' => 'Failed to parse AI decision',
                'suggested_tone' => 'professional',
                'urgency' => 'medium',
                'complexity' => 'complex',
                'risk_factors' => ['parsing_error'],
            ];
        }
    }

    protected function prepareAnalysisData(AutomationWorkflow $workflow, $executions): array
    {
        $successRate = $executions->where('status', 'completed')->count() / max($executions->count(), 1);
        $avgDuration = $executions->whereNotNull('duration')->avg('duration');
        
        $commonErrors = $executions->where('status', 'failed')
            ->pluck('error_message')
            ->countBy()
            ->sortDesc()
            ->take(5)
            ->toArray();

        return [
            'workflow' => [
                'name' => $workflow->name,
                'trigger_type' => $workflow->trigger_type,
                'actions_count' => count($workflow->actions),
                'ai_enabled' => $workflow->ai_enabled,
            ],
            'performance' => [
                'total_executions' => $executions->count(),
                'success_rate' => round($successRate * 100, 1),
                'avg_duration' => round($avgDuration ?? 0, 2),
                'common_errors' => $commonErrors,
            ],
        ];
    }

    protected function buildOptimizationPrompt(array $analysisData): string
    {
        $workflowData = json_encode($analysisData, JSON_PRETTY_PRINT);

        return <<<PROMPT
Analyze this automation workflow performance data and suggest optimizations:

{$workflowData}

Provide suggestions to improve:
1. Success rate
2. Execution speed
3. Error reduction
4. User experience
5. AI effectiveness (if applicable)

Respond with JSON:
{
    "overall_health": "excellent|good|fair|poor",
    "priority_issues": ["issue1", "issue2"],
    "suggestions": [
        {
            "category": "performance|reliability|user_experience|ai_optimization",
            "title": "suggestion title",
            "description": "detailed description",
            "impact": "high|medium|low",
            "effort": "low|medium|high"
        }
    ],
    "metrics_to_track": ["metric1", "metric2"]
}
PROMPT;
    }

    protected function parseOptimizationResponse(string $content): array
    {
        $content = trim($content);
        
        if (str_contains($content, '```')) {
            preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches);
            $content = $matches[1] ?? $content;
        }

        try {
            $optimization = json_decode($content, true);
            
            if (!is_array($optimization)) {
                throw new \Exception('Invalid JSON response');
            }

            return [
                'overall_health' => $optimization['overall_health'] ?? 'unknown',
                'priority_issues' => $optimization['priority_issues'] ?? [],
                'suggestions' => $optimization['suggestions'] ?? [],
                'metrics_to_track' => $optimization['metrics_to_track'] ?? [],
            ];

        } catch (\Exception $e) {
            return [
                'suggestions' => [],
                'reason' => 'Failed to parse optimization suggestions',
            ];
        }
    }
}