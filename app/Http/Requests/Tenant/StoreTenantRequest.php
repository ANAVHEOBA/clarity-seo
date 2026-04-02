<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:tenants,slug', 'regex:/^[a-z0-9-]+$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'favicon_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'secondary_color' => ['sometimes', 'nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'reply_to_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'custom_domain' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:tenants,custom_domain', 'regex:/^(?=.{1,253}$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/i'],
            'custom_domain_verified_at' => ['sometimes', 'nullable', 'date'],
            'public_signup_enabled' => ['sometimes', 'boolean'],
            'hide_vendor_branding' => ['sometimes', 'boolean'],
        ];
    }
}
