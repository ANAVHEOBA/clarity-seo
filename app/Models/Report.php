<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    /** @use HasFactory<\Database\Factories\ReportFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'location_id',
        'report_template_id',
        'report_schedule_id',
        'name',
        'type',
        'format',
        'status',
        'progress',
        'file_path',
        'file_name',
        'file_size',
        'date_from',
        'date_to',
        'period',
        'location_ids',
        'filters',
        'branding',
        'options',
        'error_message',
        'completed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'location_ids' => 'array',
            'filters' => 'array',
            'branding' => 'array',
            'options' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'file_size' => 'integer',
            'progress' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<ReportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    /**
     * @return BelongsTo<ReportSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'progress' => 0,
        ]);
    }

    public function markAsCompleted(string $filePath, string $fileName, int $fileSize): void
    {
        $this->update([
            'status' => 'completed',
            'progress' => 100,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'completed_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function updateProgress(int $progress): void
    {
        $this->update(['progress' => min(100, max(0, $progress))]);
    }
}
