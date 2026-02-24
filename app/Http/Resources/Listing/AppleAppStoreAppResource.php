<?php

declare(strict_types=1);

namespace App\Http\Resources\Listing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppleAppStoreAppResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'apple_app_store_account_id' => $this->apple_app_store_account_id,
            'name' => $this->name,
            'app_store_id' => $this->app_store_id,
            'bundle_id' => $this->bundle_id,
            'country_code' => $this->country_code,
            'is_active' => $this->is_active,
            'last_synced_at' => $this->last_synced_at,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
