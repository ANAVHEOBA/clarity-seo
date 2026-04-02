<?php

declare(strict_types=1);

namespace App\Http\Resources\Tenant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand_name' => $this->brand_name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo' => $this->logo,
            'logo_url' => $this->logo_url,
            'favicon_url' => $this->favicon_url,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'support_email' => $this->support_email,
            'reply_to_email' => $this->reply_to_email,
            'custom_domain' => $this->custom_domain,
            'custom_domain_verified_at' => $this->custom_domain_verified_at?->toIso8601String(),
            'public_signup_enabled' => $this->public_signup_enabled,
            'hide_vendor_branding' => $this->hide_vendor_branding,
            'white_label_enabled' => $this->isWhiteLabelEnabled(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'role' => $this->whenPivotLoaded('tenant_user', fn () => $this->pivot->role),
        ];
    }
}
