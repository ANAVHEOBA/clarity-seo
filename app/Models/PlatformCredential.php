<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformCredential extends Model
{
    use HasFactory;

    public const PLATFORM_FACEBOOK = 'facebook';

    public const PLATFORM_GOOGLE = 'google';

    public const PLATFORM_BING = 'bing';

    protected $fillable = [
        'tenant_id',
        'platform',
        'external_id',
        'access_token',
        'refresh_token',
        'token_type',
        'expires_at',
        'scopes',
        'metadata',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return $this->is_active && !$this->isExpired();
    }

    public function getPageId(): ?string
    {
        // Use external_id first (new way), fallback to metadata (old way)
        return $this->external_id ?? $this->metadata['page_id'] ?? null;
    }

    public function getAccountId(): ?string
    {
        return $this->metadata['account_id'] ?? null;
    }

    public static function getForTenant(Tenant $tenant, string $platform): ?self
    {
        return self::where('tenant_id', $tenant->id)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->first();
    }
}
