<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflow extends Model
{
    use HasFactory;

    // Trigger types
    public const TRIGGER_REVIEW_RECEIVED = 'review_received';
    public const TRIGGER_NEGATIVE_REVIEW = 'negative_review';
    public const TRIGGER_POSITIVE_REVIEW = 'positive_review';
    public const TRIGGER_LISTING_DISCREPANCY = 'listing_discrepancy';
    public const TRIGGER_SENTIMENT_NEGATIVE = 'sentiment_negative';
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_MANUAL = 'manual';

    // Action types
    public const ACTION_AI_RESPONSE = 'ai_response';
    public const ACTION_NOTIFICATION = 'notification';
    public const ACTION_ASSIGN_USER = 'assign_user';
    public const ACTION_ADD_TAG = 'add_tag';
    public const ACTION_UPDATE_LISTING = 'update_listing';
    public const ACTION_GENERATE_REPORT = 'generate_report';

    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'description',
        'is_active',
        'priority',
        'trigger_type',
        'trigger_config',
        'conditions',
        'actions',
        'execution_count',
        'last_executed_at',
        'last_successful_execution_at',
        'ai_enabled',
        'ai_config',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trigger_config' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'last_executed_at' => 'datetime',
            'last_successful_execution_at' => 'datetime',
            'ai_enabled' => 'boolean',
            'ai_config' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'workflow_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AutomationLog::class, 'workflow_id');
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function canExecute(): bool
    {
        return $this->is_active;
    }

    public function matchesTrigger(string $triggerType, array $triggerData): bool
    {
        if ($this->trigger_type !== $triggerType) {
            return false;
        }

        // Check trigger-specific conditions
        return match ($triggerType) {
            self::TRIGGER_NEGATIVE_REVIEW => $this->matchesNegativeReviewTrigger($triggerData),
            self::TRIGGER_POSITIVE_REVIEW => $this->matchesPositiveReviewTrigger($triggerData),
            self::TRIGGER_SENTIMENT_NEGATIVE => $this->matchesSentimentTrigger($triggerData),
            default => true,
        };
    }

    public function matchesConditions(array $contextData): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        foreach ($this->conditions as $condition) {
            if (!$this->evaluateCondition($condition, $contextData)) {
                return false;
            }
        }

        return true;
    }

    protected function matchesNegativeReviewTrigger(array $triggerData): bool
    {
        $config = $this->trigger_config;
        $rating = $triggerData['rating'] ?? null;
        $threshold = $config['rating_threshold'] ?? 3;

        return $rating !== null && $rating <= $threshold;
    }

    protected function matchesPositiveReviewTrigger(array $triggerData): bool
    {
        $config = $this->trigger_config;
        $rating = $triggerData['rating'] ?? null;
        $threshold = $config['rating_threshold'] ?? 4;

        return $rating !== null && $rating >= $threshold;
    }

    protected function matchesSentimentTrigger(array $triggerData): bool
    {
        $config = $this->trigger_config;
        $sentimentScore = $triggerData['sentiment_score'] ?? null;
        $threshold = $config['sentiment_threshold'] ?? 0.3;

        return $sentimentScore !== null && $sentimentScore <= $threshold;
    }

    protected function evaluateCondition(array $condition, array $contextData): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? '';

        $contextValue = data_get($contextData, $field);

        return match ($operator) {
            'equals' => $contextValue == $value,
            'not_equals' => $contextValue != $value,
            'contains' => is_string($contextValue) && str_contains($contextValue, $value),
            'not_contains' => is_string($contextValue) && !str_contains($contextValue, $value),
            'greater_than' => is_numeric($contextValue) && $contextValue > $value,
            'less_than' => is_numeric($contextValue) && $contextValue < $value,
            'in' => is_array($value) && in_array($contextValue, $value),
            'not_in' => is_array($value) && !in_array($contextValue, $value),
            default => false,
        };
    }

    public function incrementExecutionCount(): void
    {
        $this->increment('execution_count');
        $this->update(['last_executed_at' => now()]);
    }

    public function markSuccessfulExecution(): void
    {
        $this->update(['last_successful_execution_at' => now()]);
    }
}