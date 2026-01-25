<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Listing extends Model
{
    use HasFactory;

    public const PLATFORM_FACEBOOK = 'facebook';

    public const PLATFORM_GOOGLE = 'google';

    public const PLATFORM_GOOGLE_MY_BUSINESS = 'google_my_business';

    public const PLATFORM_BING = 'bing';

    public const PLATFORM_GOOGLE_PLAY = 'google_play';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'location_id',
        'platform',
        'external_id',
        'status',
        'name',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'phone',
        'website',
        'categories',
        'business_hours',
        'description',
        'attributes',
        'photos',
        'latitude',
        'longitude',
        'discrepancies',
        'last_synced_at',
        'last_published_at',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'business_hours' => 'array',
            'attributes' => 'array',
            'photos' => 'array',
            'discrepancies' => 'array',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'last_synced_at' => 'datetime',
            'last_published_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isFacebook(): bool
    {
        return $this->platform === self::PLATFORM_FACEBOOK;
    }

    public function isGoogle(): bool
    {
        return $this->platform === self::PLATFORM_GOOGLE;
    }

    public function isBing(): bool
    {
        return $this->platform === self::PLATFORM_BING;
    }

    public function isGooglePlay(): bool
    {
        return $this->platform === self::PLATFORM_GOOGLE_PLAY;
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function hasDiscrepancies(): bool
    {
        return !empty($this->discrepancies);
    }

    public function markAsSynced(): void
    {
        $this->update([
            'status' => self::STATUS_SYNCED,
            'last_synced_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsError(string $message): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'error_message' => $message,
        ]);
    }

    public function setDiscrepancies(array $discrepancies): void
    {
        $this->update(['discrepancies' => $discrepancies]);
    }
}
