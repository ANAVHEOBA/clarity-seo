<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppleAppStoreApp extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'apple_app_store_account_id',
        'name',
        'app_store_id',
        'bundle_id',
        'country_code',
        'is_active',
        'last_synced_at',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AppleAppStoreAccount::class, 'apple_app_store_account_id');
    }
}
