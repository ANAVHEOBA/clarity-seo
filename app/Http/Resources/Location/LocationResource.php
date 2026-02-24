<?php

declare(strict_types=1);

namespace App\Http\Resources\Location;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'address2' => $this->address2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'phone' => $this->phone,
            'website' => $this->website,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'primary_category' => $this->primary_category,
            'categories' => $this->categories,
            'business_hours' => $this->business_hours,
            'status' => $this->status,
            'apple_app_store_app_id' => $this->apple_app_store_app_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
