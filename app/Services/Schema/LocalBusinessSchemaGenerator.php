<?php

namespace App\Services\Schema;

use App\Models\Location;

class LocalBusinessSchemaGenerator extends SchemaGenerator
{
    protected string $type = 'LocalBusiness';

    protected Location $location;

    public function __construct(Location $location)
    {
        $this->location = $location;
    }

    protected function generateData(): array
    {
        return $this->cleanArray([
            'name' => $this->location->name,
            'address' => $this->generateAddress(),
            'telephone' => $this->location->phone,
            'url' => $this->formatUrl($this->location->website),
            'aggregateRating' => $this->generateAggregateRating(),
            'sameAs' => $this->generateSameAs(),
            'geo' => $this->generateGeo(),
        ]);
    }

    protected function getRequiredFields(): array
    {
        return [
            'name',
            'address',
        ];
    }

    private function generateAddress(): array
    {
        return $this->createPostalAddressSchema([
            'street' => $this->location->address,
            'city' => $this->location->city,
            'state' => $this->location->state,
            'postal_code' => $this->location->postal_code,
            'country' => $this->location->country ?? 'US',
        ]);
    }

    private function generateAggregateRating(): ?array
    {
        $avgRating = $this->location->reviews()
            ->whereNotNull('rating')
            ->avg('rating');

        $reviewCount = $this->location->reviews()->count();

        if ($avgRating === null || $reviewCount === 0) {
            return null;
        }

        return $this->createAggregateRatingSchema($avgRating, $reviewCount);
    }

    private function generateSameAs(): array
    {
        $urls = [];

        if ($this->location->website) {
            $urls[] = $this->formatUrl($this->location->website);
        }

        return array_filter($urls);
    }

    private function generateGeo(): ?array
    {
        if (!$this->location->latitude || !$this->location->longitude) {
            return null;
        }

        return [
            '@type' => 'GeoCoordinates',
            'latitude' => (float) $this->location->latitude,
            'longitude' => (float) $this->location->longitude,
        ];
    }
}
