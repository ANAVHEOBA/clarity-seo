<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'address',
        'address2',
        'city',
        'state',
        'postal_code',
        'country',
        'phone',
        'website',
        'latitude',
        'longitude',
        'primary_category',
        'categories',
        'business_hours',
        'status',
        'google_place_id',
        'yelp_business_id',
        'facebook_page_id',
        'reviews_synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'business_hours' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'reviews_synced_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasGooglePlaceId(): bool
    {
        return !empty($this->google_place_id);
    }

    public function hasYelpBusinessId(): bool
    {
        return !empty($this->yelp_business_id);
    }

    public function hasFacebookPageId(): bool
    {
        return !empty($this->facebook_page_id);
    }
}
