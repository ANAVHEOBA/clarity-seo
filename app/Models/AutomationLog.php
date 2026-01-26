<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationLog extends Model
{
    use HasFactory;

    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    protected $fillable = [
        'workflow_id',
        'execution_id',
        'level',
        'message',
        'context',
        'action_type',
        'action_index',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (AutomationLog $log) {
            $log->created_at = now();
        });
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'execution_id');
    }

    public function isError(): bool
    {
        return $this->level === self::LEVEL_ERROR;
    }

    public function isWarning(): bool
    {
        return $this->level === self::LEVEL_WARNING;
    }

    public function isInfo(): bool
    {
        return $this->level === self::LEVEL_INFO;
    }

    public function isDebug(): bool
    {
        return $this->level === self::LEVEL_DEBUG;
    }
}