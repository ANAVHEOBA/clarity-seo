<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'brand_name' => null,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'logo_url' => null,
            'favicon_url' => null,
            'primary_color' => null,
            'secondary_color' => null,
            'support_email' => null,
            'reply_to_email' => null,
            'custom_domain' => null,
            'custom_domain_verified_at' => null,
            'public_signup_enabled' => false,
            'hide_vendor_branding' => false,
            'plan' => 'free',
            'white_label_enabled' => false,
        ];
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'premium',
            'hide_vendor_branding' => true,
            'white_label_enabled' => true,
        ]);
    }

    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'enterprise',
            'hide_vendor_branding' => true,
            'white_label_enabled' => true,
        ]);
    }

    public function brandedPortal(?string $domain = null): static
    {
        return $this->state(fn (array $attributes) => [
            'brand_name' => ($attributes['name'] ?? 'Branded Portal').' Portal',
            'primary_color' => '#14532D',
            'secondary_color' => '#86EFAC',
            'support_email' => 'support@example.test',
            'reply_to_email' => 'support@example.test',
            'custom_domain' => $domain ?? 'portal.example.test',
            'custom_domain_verified_at' => now(),
            'public_signup_enabled' => false,
            'hide_vendor_branding' => true,
            'white_label_enabled' => true,
        ]);
    }
}
