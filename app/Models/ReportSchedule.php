<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportSchedule extends Model
{
    /** @use HasFactory<\Database\Factories\ReportScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'report_template_id',
        'name',
        'description',
        'type',
        'format',
        'frequency',
        'day_of_week',
        'day_of_month',
        'time_of_day',
        'timezone',
        'period',
        'location_ids',
        'filters',
        'branding',
        'options',
        'recipients',
        'is_active',
        'last_run_at',
        'next_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location_ids' => 'array',
            'filters' => 'array',
            'branding' => 'array',
            'options' => 'array',
            'recipients' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'day_of_month' => 'integer',
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
     * @return BelongsTo<ReportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function isDaily(): bool
    {
        return $this->frequency === 'daily';
    }

    public function isWeekly(): bool
    {
        return $this->frequency === 'weekly';
    }

    public function isMonthly(): bool
    {
        return $this->frequency === 'monthly';
    }

    public function calculateNextRunAt(): void
    {
        $now = now()->setTimezone($this->timezone);
        $time = explode(':', $this->time_of_day);
        $hour = (int) $time[0];
        $minute = (int) ($time[1] ?? 0);

        $nextRun = match ($this->frequency) {
            'daily' => $now->copy()->setTime($hour, $minute)->addDay(),
            'weekly' => $now->copy()->next($this->day_of_week)->setTime($hour, $minute),
            'monthly' => $now->copy()->setDay($this->day_of_month ?? 1)->setTime($hour, $minute)->addMonth(),
            default => $now->copy()->addDay(),
        };

        if ($nextRun->isPast()) {
            $nextRun = match ($this->frequency) {
                'daily' => $nextRun->addDay(),
                'weekly' => $nextRun->addWeek(),
                'monthly' => $nextRun->addMonth(),
                default => $nextRun->addDay(),
            };
        }

        $this->update(['next_run_at' => $nextRun->setTimezone('UTC')]);
    }

    public function markAsRun(): void
    {
        $this->update(['last_run_at' => now()]);
        $this->calculateNextRunAt();
    }
}
