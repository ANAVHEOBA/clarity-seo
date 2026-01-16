<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Models\Listing;
use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookService
{
    protected string $baseUrl;

    protected string $graphVersion;

    public function __construct()
    {
        $this->baseUrl = config('facebook.base_url');
        $this->graphVersion = config('facebook.graph_version');
    }

    /**
     * Get the full API URL for a given endpoint.
     */
    protected function apiUrl(string $endpoint): string
    {
        return "{$this->baseUrl}/{$this->graphVersion}/{$endpoint}";
    }

    /**
     * Get all Facebook Pages the user has access to.
     *
     * @return array<int, array{id: string, name: string, access_token: string}>|null
     */
    public function getPages(string $accessToken): ?array
    {
        try {
            $response = Http::get($this->apiUrl('me/accounts'), [
                'access_token' => $accessToken,
                'fields' => 'id,name,access_token,category,location,phone,website,hours,single_line_address',
            ]);

            if (!$response->successful()) {
                Log::error('Facebook API error: Failed to get pages', [
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return null;
            }

            return $response->json('data', []);
        } catch (ConnectionException $e) {
            Log::error('Facebook connection error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get details for a specific Facebook Page.
     *
     * @return array<string, mixed>|null
     */
    public function getPageDetails(string $pageId, string $accessToken): ?array
    {
        try {
            $response = Http::get($this->apiUrl($pageId), [
                'access_token' => $accessToken,
                'fields' => implode(',', [
                    'id',
                    'name',
                    'about',
                    'description',
                    'category',
                    'category_list',
                    'phone',
                    'website',
                    'emails',
                    'location',
                    'single_line_address',
                    'hours',
                    'is_permanently_closed',
                    'verification_status',
                    'fan_count',
                    'followers_count',
                    'rating_count',
                    'overall_star_rating',
                    'cover',
                    'picture',
                ]),
            ]);

            if (!$response->successful()) {
                Log::error('Facebook API error: Failed to get page details', [
                    'page_id' => $pageId,
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            Log::error('Facebook connection error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Update a Facebook Page's information.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePage(string $pageId, string $pageAccessToken, array $data): bool
    {
        try {
            $updateData = [];

            if (isset($data['about'])) {
                $updateData['about'] = $data['about'];
            }
            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }
            if (isset($data['phone'])) {
                $updateData['phone'] = $data['phone'];
            }
            if (isset($data['website'])) {
                $updateData['website'] = $data['website'];
            }
            if (isset($data['hours'])) {
                $updateData['hours'] = $data['hours'];
            }

            if (empty($updateData)) {
                return true;
            }

            $updateData['access_token'] = $pageAccessToken;

            $response = Http::post($this->apiUrl($pageId), $updateData);

            if (!$response->successful()) {
                Log::error('Facebook API error: Failed to update page', [
                    'page_id' => $pageId,
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return false;
            }

            return true;
        } catch (ConnectionException $e) {
            Log::error('Facebook connection error', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Sync a location's listing from Facebook.
     */
    public function syncListing(Location $location, PlatformCredential $credential): ?Listing
    {
        $pageId = $credential->getPageId();

        if (!$pageId) {
            Log::error('No Facebook page ID configured', ['tenant_id' => $credential->tenant_id]);

            return null;
        }

        $pageData = $this->getPageDetails($pageId, $credential->access_token);

        if (!$pageData) {
            return null;
        }

        $listing = Listing::updateOrCreate(
            [
                'location_id' => $location->id,
                'platform' => Listing::PLATFORM_FACEBOOK,
            ],
            [
                'external_id' => $pageData['id'],
                'status' => Listing::STATUS_SYNCED,
                'name' => $pageData['name'] ?? null,
                'address' => $pageData['location']['street'] ?? null,
                'city' => $pageData['location']['city'] ?? null,
                'state' => $pageData['location']['state'] ?? null,
                'postal_code' => $pageData['location']['zip'] ?? null,
                'country' => $pageData['location']['country'] ?? null,
                'phone' => $pageData['phone'] ?? null,
                'website' => $pageData['website'] ?? null,
                'categories' => $pageData['category_list'] ?? null,
                'business_hours' => $pageData['hours'] ?? null,
                'description' => $pageData['about'] ?? $pageData['description'] ?? null,
                'latitude' => $pageData['location']['latitude'] ?? null,
                'longitude' => $pageData['location']['longitude'] ?? null,
                'attributes' => [
                    'fan_count' => $pageData['fan_count'] ?? null,
                    'followers_count' => $pageData['followers_count'] ?? null,
                    'rating_count' => $pageData['rating_count'] ?? null,
                    'overall_star_rating' => $pageData['overall_star_rating'] ?? null,
                    'verification_status' => $pageData['verification_status'] ?? null,
                ],
                'last_synced_at' => now(),
            ]
        );

        // Check for discrepancies
        $discrepancies = $this->detectDiscrepancies($location, $listing);
        if (!empty($discrepancies)) {
            $listing->setDiscrepancies($discrepancies);
        }

        return $listing;
    }

    /**
     * Publish location data to Facebook Page.
     */
    public function publishListing(Location $location, PlatformCredential $credential): bool
    {
        $pageId = $credential->getPageId();
        $pageAccessToken = $credential->metadata['page_access_token'] ?? $credential->access_token;

        if (!$pageId) {
            return false;
        }

        $data = [
            'about' => $location->name,
            'phone' => $location->phone,
            'website' => $location->website,
        ];

        $success = $this->updatePage($pageId, $pageAccessToken, $data);

        if ($success) {
            $listing = Listing::where('location_id', $location->id)
                ->where('platform', Listing::PLATFORM_FACEBOOK)
                ->first();

            if ($listing) {
                $listing->update([
                    'last_published_at' => now(),
                    'status' => Listing::STATUS_SYNCED,
                ]);
            }
        }

        return $success;
    }

    /**
     * Detect discrepancies between local data and Facebook data.
     *
     * @return array<string, array{local: mixed, platform: mixed}>
     */
    protected function detectDiscrepancies(Location $location, Listing $listing): array
    {
        $discrepancies = [];

        $fields = [
            'name' => 'name',
            'phone' => 'phone',
            'website' => 'website',
            'address' => 'address',
            'city' => 'city',
            'state' => 'state',
            'postal_code' => 'postal_code',
        ];

        foreach ($fields as $locationField => $listingField) {
            $localValue = $location->{$locationField};
            $platformValue = $listing->{$listingField};

            if ($localValue && $platformValue && strtolower(trim($localValue)) !== strtolower(trim($platformValue))) {
                $discrepancies[$locationField] = [
                    'local' => $localValue,
                    'platform' => $platformValue,
                ];
            }
        }

        return $discrepancies;
    }

    /**
     * Store Facebook credentials for a tenant.
     */
    public function storeCredentials(
        Tenant $tenant,
        string $accessToken,
        string $pageId,
        ?string $pageAccessToken = null,
        ?array $scopes = null
    ): PlatformCredential {
        return PlatformCredential::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'platform' => PlatformCredential::PLATFORM_FACEBOOK,
                'external_id' => $pageId,  // FIX: Include page_id to support multiple pages
            ],
            [
                'access_token' => $accessToken,
                'scopes' => $scopes ?? config('facebook.default_permissions'),
                'metadata' => [
                    'page_id' => $pageId,
                    'page_access_token' => $pageAccessToken,
                ],
                'is_active' => true,
            ]
        );
    }

    /**
     * Get credentials for a tenant.
     */
    public function getCredentials(Tenant $tenant): ?PlatformCredential
    {
        return PlatformCredential::getForTenant($tenant, PlatformCredential::PLATFORM_FACEBOOK);
    }

    /**
     * Check if tenant has valid Facebook credentials.
     */
    public function hasValidCredentials(Tenant $tenant): bool
    {
        $credential = $this->getCredentials($tenant);

        return $credential && $credential->isValid();
    }
}
