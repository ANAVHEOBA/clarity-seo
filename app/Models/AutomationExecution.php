<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationExecution extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'workflow_id',
        'trigger_data',
        'trigger_source',
        'status',
        'started_at',
        'completed_at',
        'error_message',
        'results',
        'actions_completed',
        'actions_failed',
        'ai_involved',
        'ai_decisions',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger_data' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'results' => 'array',
            'ai_involved' => 'boolean',
            'ai_decisions' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AutomationLog::class, 'execution_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsRunning(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(array $results = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'results' => $results,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    public function incrementActionsCompleted(): void
    {
        $this->increment('actions_completed');
    }

    public function incrementActionsFailed(): void
    {
        $this->increment('actions_failed');
    }

    public function getDurationAttribute(): ?int
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }

        return (int) $this->completed_at->diffInSeconds($this->started_at);
    }
}