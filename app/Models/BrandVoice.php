<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandVoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'tone',
        'guidelines',
        'example_responses',
        'is_default',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'example_responses' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reviewResponses(): HasMany
    {
        return $this->hasMany(ReviewResponse::class);
    }

    public function aiResponseHistories(): HasMany
    {
        return $this->hasMany(AIResponseHistory::class);
    }

    public function markAsDefault(): void
    {
        // Unset any existing default for this tenant
        static::where('tenant_id', $this->tenant_id)
            ->where('id', '!=', $this->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
