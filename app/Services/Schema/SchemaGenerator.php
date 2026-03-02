<?php

namespace App\Services\Schema;

use Carbon\Carbon;
use Illuminate\Support\Arr;

abstract class SchemaGenerator
{
    /**
     * Schema context URL
     */
    protected const SCHEMA_CONTEXT = 'https://schema.org';

    /**
     * Schema type - override in child classes
     */
    protected string $type = '';

    /**
     * Generate schema data
     */
    abstract protected function generateData(): array;

    /**
     * Get required fields for validation
     */
    abstract protected function getRequiredFields(): array;

    /**
     * Generate complete JSON-LD schema
     */
    public function generate(): array
    {
        $data = $this->generateData();

        return [
            '@context' => self::SCHEMA_CONTEXT,
            '@type' => $this->type,
            ...$data,
        ];
    }

    /**
     * Get JSON-LD as string
     */
    public function toJson(): string
    {
        return json_encode($this->generate(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validate schema structure
     */
    public function validate(): bool
    {
        $schema = $this->generate();

        // Check required fields
        foreach ($this->getRequiredFields() as $field) {
            if (!Arr::has($schema, $field)) {
                return false;
            }
        }

        // Check context and type
        if ($schema['@context'] !== self::SCHEMA_CONTEXT) {
            return false;
        }

        if ($schema['@type'] !== $this->type) {
            return false;
        }

        return true;
    }

    /**
     * Format URL - ensure absolute
     */
    protected function formatUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        if (!str_starts_with($url, 'http')) {
            return url($url);
        }

        return $url;
    }

    /**
     * Format date for schema.org (ISO 8601)
     */
    protected function formatDate($date): ?string
    {
        if (!$date) {
            return null;
        }

        if ($date instanceof Carbon) {
            return $date->toIso8601String();
        }

        return Carbon::parse($date)->toIso8601String();
    }

    /**
     * Format rating value (1-5)
     */
    protected function formatRating($rating): ?int
    {
        if ($rating === null) {
            return null;
        }

        return max(1, min(5, (int) $rating));
    }

    /**
     * Create person schema
     */
    protected function createPersonSchema(string $name, ?string $url = null, ?string $image = null): array
    {
        $person = [
            '@type' => 'Person',
            'name' => $name,
        ];

        if ($url) {
            $person['url'] = $this->formatUrl($url);
        }

        if ($image) {
            $person['image'] = $this->formatUrl($image);
        }

        return $person;
    }

    /**
     * Create organization schema
     */
    protected function createOrganizationSchema(string $name, ?string $url = null, ?string $image = null): array
    {
        $org = [
            '@type' => 'Organization',
            'name' => $name,
        ];

        if ($url) {
            $org['url'] = $this->formatUrl($url);
        }

        if ($image) {
            $org['image'] = $this->formatUrl($image);
        }

        return $org;
    }

    /**
     * Create postal address schema
     */
    protected function createPostalAddressSchema(array $address): array
    {
        return [
            '@type' => 'PostalAddress',
            'streetAddress' => $address['street'] ?? null,
            'addressLocality' => $address['city'] ?? null,
            'addressRegion' => $address['state'] ?? null,
            'postalCode' => $address['postal_code'] ?? null,
            'addressCountry' => $address['country'] ?? 'US',
        ];
    }

    /**
     * Create rating schema
     */
    protected function createRatingSchema($value, ?int $worstRating = 1, ?int $bestRating = 5): array
    {
        return [
            '@type' => 'Rating',
            'ratingValue' => $this->formatRating($value),
            'worstRating' => $worstRating,
            'bestRating' => $bestRating,
        ];
    }

    /**
     * Create aggregate rating schema
     */
    protected function createAggregateRatingSchema($ratingValue, int $reviewCount, ?int $worstRating = 1, ?int $bestRating = 5): array
    {
        return [
            '@type' => 'AggregateRating',
            'ratingValue' => $this->formatRating($ratingValue),
            'reviewCount' => (int) $reviewCount,
            'worstRating' => $worstRating,
            'bestRating' => $bestRating,
        ];
    }

    /**
     * Clean null values from array
     */
    protected function cleanArray(array $data): array
    {
        return array_filter($data, fn ($value) => $value !== null);
    }
}
