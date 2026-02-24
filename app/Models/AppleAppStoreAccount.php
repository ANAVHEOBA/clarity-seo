<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppleAppStoreAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'issuer_id',
        'key_id',
        'private_key',
        'is_active',
        'last_synced_at',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected $hidden = [
        'private_key',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function apps(): HasMany
    {
        return $this->hasMany(AppleAppStoreApp::class);
    }

    public function isValid(): bool
    {
        return $this->is_active;
    }
}
