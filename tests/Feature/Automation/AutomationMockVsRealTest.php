<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Models\AutomationWorkflow;
use App\Models\Location;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AIResponse\AIResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test to demonstrate the difference between mocked and real API calls
 */
class AutomationMockVsRealTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected Location $location;
    protected Review $review;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant = Tenant::factory()->create();
        $this->tenant->users()->attach($this->user, ['role' => 'owner']);
        $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $this->review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 2,
            'content' => 'The service was terrible and the food was cold.',
        ]);
    }

    public function test_mocked_api_response(): void
    {
        // Mock the API response
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'MOCKED RESPONSE: Thank you for your feedback. We apologize for the poor experience.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $aiService = app(AIResponseService::class);
        $result = $aiService->generateResponse($this->review, $this->user, [
            'tone' => 'apologetic',
            'language' => 'en',
        ]);

        $this->assertNotNull($result);
        $this->assertStringStartsWith('MOCKED RESPONSE:', $result['response']->content);
        
        echo "\n=== MOCKED API TEST ===\n";
        echo "Review: {$this->review->content}\n";
        echo "Response: {$result['response']->content}\n";
        echo "Source: Mocked HTTP response\n";
        echo "=======================\n";

        // Verify HTTP was called
        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions' &&
                   $request['model'] === config('openrouter.model');
        });
    }

    public function test_real_api_response(): void
    {
        // Skip if no API key
        if (empty(config('openrouter.api_key'))) {
            $this->markTestSkipped('OpenRouter API key not configured');
        }

        $this->markTestSkipped('Skipping real API test to avoid charges. Remove this line to test with real API.');

        // No HTTP mocking - use real API
        $aiService = app(AIResponseService::class);
        
        try {
            $result = $aiService->generateResponse($this->review, $this->user, [
                'tone' => 'apologetic',
                'language' => 'en',
            ]);

            $this->assertNotNull($result);
            $this->assertStringNotContainsString('MOCKED RESPONSE:', $result['response']->content);
            
            echo "\n=== REAL API TEST ===\n";
            echo "Review: {$this->review->content}\n";
            echo "Response: {$result['response']->content}\n";
            echo "Source: Real OpenRouter API\n";
            echo "Model: " . config('openrouter.model') . "\n";
            echo "=====================\n";

        } catch (\Exception $e) {
            $this->fail("Real API call failed: " . $e->getMessage());
        }
    }

    public function test_configuration_values_are_used(): void
    {
        // Test that the service uses actual .env values
        $this->assertEquals(
            config('openrouter.api_key'),
            env('OPENROUTER_API_KEY'),
            'Config should match .env value'
        );

        $this->assertEquals(
            config('openrouter.model'),
            env('OPENROUTER_MODEL', 'qwen/qwen-2.5-vl-7b-instruct:free'),
            'Model config should match .env value'
        );

        echo "\n=== CONFIGURATION TEST ===\n";
        echo "API Key from config: " . (config('openrouter.api_key') ? 'SET' : 'NOT SET') . "\n";
        echo "API Key from .env: " . (env('OPENROUTER_API_KEY') ? 'SET' : 'NOT SET') . "\n";
        echo "Model from config: " . config('openrouter.model') . "\n";
        echo "Model from .env: " . env('OPENROUTER_MODEL', 'default') . "\n";
        echo "===========================\n";
    }

    public function test_mock_vs_real_behavior_difference(): void
    {
        echo "\n=== MOCK vs REAL BEHAVIOR ===\n";
        echo "MOCKED TESTS:\n";
        echo "- Use Http::fake() to intercept HTTP calls\n";
        echo "- Return predefined responses instantly\n";
        echo "- No API charges or rate limits\n";
        echo "- Consistent, predictable responses\n";
        echo "- Test automation logic without external dependencies\n";
        echo "\n";
        echo "REAL API TESTS:\n";
        echo "- Make actual HTTP calls to OpenRouter\n";
        echo "- Use your OPENROUTER_API_KEY from .env\n";
        echo "- Consume API credits/tokens\n";
        echo "- Subject to rate limits and network issues\n";
        echo "- Test actual AI model responses\n";
        echo "- Verify end-to-end integration\n";
        echo "\n";
        echo "WHEN TO USE EACH:\n";
        echo "- Mocked: Unit tests, CI/CD, development\n";
        echo "- Real API: Integration tests, manual verification\n";
        echo "=============================\n";

        $this->assertTrue(true); // Prevent risky test warning
    }
}