<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Models\Listing;
use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ListingService
{
    public function __construct(
        protected FacebookService $facebookService,
        protected GoogleMyBusinessService $googleMyBusinessService
    ) {}

    /**
     * Get all listings for a tenant.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForTenant(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $locationIds = $tenant->locations()->pluck('id');

        $query = Listing::whereIn('location_id', $locationIds)
            ->with('location');

        if (! empty($filters['platform'])) {
            $query->where('platform', $filters['platform']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        if (isset($filters['has_discrepancies']) && $filters['has_discrepancies']) {
            $query->whereNotNull('discrepancies')
                ->where('discrepancies', '!=', '{}')
                ->where('discrepancies', '!=', '[]');
        }

        $sortBy = $filters['sort_by'] ?? 'updated_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get listings for a specific location.
     */
    public function listForLocation(Location $location): Collection
    {
        return $location->listings()->get();
    }

    /**
     * Get a single listing.
     */
    public function find(int $id): ?Listing
    {
        return Listing::with('location')->find($id);
    }

    /**
     * Sync listing from a platform.
     */
    public function syncFromPlatform(Location $location, string $platform): ?Listing
    {
        $tenant = $location->tenant;
        $credential = PlatformCredential::getForTenant($tenant, $platform);

        if (! $credential || ! $credential->isValid()) {
            return null;
        }

        return match ($platform) {
            Listing::PLATFORM_FACEBOOK => $this->facebookService->syncListing($location, $credential),
            Listing::PLATFORM_GOOGLE_MY_BUSINESS => $this->googleMyBusinessService->syncListing($location, $credential),
            default => null,
        };
    }

    /**
     * Publish location data to a platform.
     */
    public function publishToPlatform(Location $location, string $platform): bool
    {
        $tenant = $location->tenant;
        $credential = PlatformCredential::getForTenant($tenant, $platform);

        if (! $credential || ! $credential->isValid()) {
            return false;
        }

        return match ($platform) {
            Listing::PLATFORM_FACEBOOK => $this->facebookService->publishListing($location, $credential),
            default => false,
        };
    }

    /**
     * Sync all listings for a location.
     *
     * @return array<string, Listing|null>
     */
    public function syncAllPlatforms(Location $location): array
    {
        $results = [];
        $tenant = $location->tenant;

        $platforms = [
            Listing::PLATFORM_FACEBOOK,
            Listing::PLATFORM_GOOGLE_MY_BUSINESS,
        ];

        foreach ($platforms as $platform) {
            $credential = PlatformCredential::getForTenant($tenant, $platform);

            if ($credential && $credential->isValid()) {
                $results[$platform] = $this->syncFromPlatform($location, $platform);
            }
        }

        return $results;
    }

    /**
     * Publish to all configured platforms.
     *
     * @return array<string, bool>
     */
    public function publishToAllPlatforms(Location $location): array
    {
        $results = [];
        $tenant = $location->tenant;

        $platforms = [
            Listing::PLATFORM_FACEBOOK,
        ];

        foreach ($platforms as $platform) {
            $credential = PlatformCredential::getForTenant($tenant, $platform);

            if ($credential && $credential->isValid()) {
                $results[$platform] = $this->publishToPlatform($location, $platform);
            }
        }

        return $results;
    }

    /**
     * Get listing statistics for a tenant.
     *
     * @return array<string, mixed>
     */
    public function getStats(Tenant $tenant, ?Location $location = null): array
    {
        $locationIds = $location
            ? [$location->id]
            : $tenant->locations()->pluck('id')->toArray();

        $query = Listing::whereIn('location_id', $locationIds);

        $total = $query->count();

        $byPlatform = (clone $query)
            ->select('platform', DB::raw('count(*) as count'))
            ->groupBy('platform')
            ->pluck('count', 'platform')
            ->toArray();

        $byStatus = (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $withDiscrepancies = (clone $query)
            ->whereNotNull('discrepancies')
            ->where('discrepancies', '!=', '{}')
            ->where('discrepancies', '!=', '[]')
            ->count();

        $recentlySynced = (clone $query)
            ->where('last_synced_at', '>=', now()->subDay())
            ->count();

        return [
            'total_listings' => $total,
            'by_platform' => [
                'facebook' => $byPlatform['facebook'] ?? 0,
                'google' => $byPlatform['google'] ?? 0,
                'bing' => $byPlatform['bing'] ?? 0,
            ],
            'by_status' => [
                'pending' => $byStatus['pending'] ?? 0,
                'active' => $byStatus['active'] ?? 0,
                'synced' => $byStatus['synced'] ?? 0,
                'error' => $byStatus['error'] ?? 0,
            ],
            'with_discrepancies' => $withDiscrepancies,
            'recently_synced' => $recentlySynced,
        ];
    }

    /**
     * Get available platforms for a tenant.
     *
     * @return array<string, array{connected: bool, page_id: string|null}>
     */
    public function getAvailablePlatforms(Tenant $tenant): array
    {
        $platforms = [
            Listing::PLATFORM_FACEBOOK => [
                'connected' => false,
                'page_id' => null,
            ],
            Listing::PLATFORM_GOOGLE => [
                'connected' => false,
                'page_id' => null,
            ],
            Listing::PLATFORM_BING => [
                'connected' => false,
                'page_id' => null,
            ],
        ];

        $credentials = PlatformCredential::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();

        foreach ($credentials as $credential) {
            if (isset($platforms[$credential->platform])) {
                $platforms[$credential->platform] = [
                    'connected' => $credential->isValid(),
                    'page_id' => $credential->getPageId(),
                ];
            }
        }

        return $platforms;
    }
}
