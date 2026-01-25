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

class GoogleMyBusinessService
{
    protected string $clientId;

    protected string $clientSecret;

    protected array $scopes;

    public function __construct()
    {
        $this->clientId = config('google.my_business.client_id');
        $this->clientSecret = config('google.my_business.client_secret');
        $this->scopes = config('google.my_business.scopes');
    }

    /**
     * Get the Google OAuth Login URL.
     */
    public function getLoginUrl(string $redirectUri, string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', $this->scopes),
            'response_type' => 'code',
            'access_type' => 'offline', // Get refresh token
            'prompt' => 'consent', // Force consent to always get refresh token
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token.
     *
     * @return array<string, mixed>|null
     */
    public function getAccessTokenFromCode(string $code, string $redirectUri): ?array
    {
        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]);

            if (!$response->successful()) {
                Log::error('Google OAuth error: Failed to get access token', [
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                    'error_description' => $response->json('error_description'),
                ]);

                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            Log::error('Google OAuth connection error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Refresh access token using refresh token.
     *
     * @return array<string, mixed>|null
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token',
            ]);

            if (!$response->successful()) {
                Log::error('Google OAuth error: Failed to refresh token', [
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            Log::error('Google OAuth connection error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get all business accounts the user has access to.
     *
     * @return array<int, array{name: string, accountName: string}> |null
     */
    public function getAccounts(string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

            if (!$response->successful()) {
                Log::error('Google My Business API error: Failed to get accounts', [
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return null;
            }

            return $response->json('accounts', []);
        } catch (ConnectionException $e) {
            Log::error('Google My Business connection error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get all locations for a business account.
     *
     * @param  string  $accountId  Format: accounts/{account_id}
     * @return array<int, array{name: string, title: string}> |null
     */
    public function getLocations(string $accountId, string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$accountId}/locations", [
                    'readMask' => 'name,title,storefrontAddress,phoneNumbers,websiteUri,categories,regularHours',
                ]);

            if (!$response->successful()) {
                Log::error('Google My Business API error: Failed to get locations', [
                    'account_id' => $accountId,
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return null;
            }

            return $response->json('locations', []);
        } catch (ConnectionException $e) {
            Log::error('Google My Business connection error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get detailed information for a specific location.
     *
     * @param  string  $locationName  Format: locations/{location_id}
     * @return array<string, mixed>|null
     */
    public function getLocationDetails(string $locationName, string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$locationName}", [
                    'readMask' => implode(',', [
                        'name',
                        'title',
                        'storefrontAddress',
                        'phoneNumbers',
                        'websiteUri',
                        'categories',
                        'regularHours',
                        'specialHours',
                        'serviceArea',
                        'labels',
                        'adWordsLocationExtensions',
                        'latlng',
                        'openInfo',
                        'metadata',
                        'profile',
                        'relationshipData',
                    ]),
                ]);

            if (!$response->successful()) {
                Log::error('Google My Business API error: Failed to get location details', [
                    'location_name' => $locationName,
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            Log::error('Google My Business connection error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Sync a location's listing from Google My Business.
     */
    public function syncListing(Location $location, PlatformCredential $credential): ?Listing
    {
        $locationName = $credential->metadata['location_name'] ?? $credential->external_id;

        if (!$locationName) {
            Log::error('No Google My Business location name configured', ['tenant_id' => $credential->tenant_id]);

            return null;
        }

        $locationData = $this->getLocationDetails($locationName, $credential->access_token);

        if (!$locationData) {
            return null;
        }

        // Extract address components
        $address = $locationData['storefrontAddress'] ?? [];
        $addressLines = $address['addressLines'] ?? [];
        $phoneNumbers = $locationData['phoneNumbers'] ?? [];
        $primaryPhone = $phoneNumbers[0] ?? null;

        $listing = Listing::updateOrCreate(
            [
                'location_id' => $location->id,
                'platform' => Listing::PLATFORM_GOOGLE_MY_BUSINESS,
            ],
            [
                'external_id' => $locationData['name'],
                'status' => Listing::STATUS_SYNCED,
                'name' => $locationData['title'] ?? null,
                'address' => $addressLines[0] ?? null,
                'city' => $address['locality'] ?? null,
                'state' => $address['administrativeArea'] ?? null,
                'postal_code' => $address['postalCode'] ?? null,
                'country' => $address['regionCode'] ?? null,
                'phone' => $primaryPhone,
                'website' => $locationData['websiteUri'] ?? null,
                'categories' => $locationData['categories'] ?? null,
                'business_hours' => $locationData['regularHours'] ?? null,
                'latitude' => $locationData['latlng']['latitude'] ?? null,
                'longitude' => $locationData['latlng']['longitude'] ?? null,
                'attributes' => [
                    'labels' => $locationData['labels'] ?? null,
                    'open_info' => $locationData['openInfo'] ?? null,
                    'metadata' => $locationData['metadata'] ?? null,
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
     * Publish location data to Google My Business.
     * Note: Google My Business API has limited write capabilities.
     */
    public function publishListing(Location $location, PlatformCredential $credential): bool
    {
        $locationName = $credential->metadata['location_name'] ?? $credential->external_id;

        if (!$locationName) {
            return false;
        }

        // Google My Business API has very limited update capabilities
        // Most fields are read-only and can only be updated through the Google My Business dashboard
        // This is a placeholder for future implementation when/if Google expands the API

        Log::warning('Google My Business API has limited write capabilities', [
            'location_name' => $locationName,
            'message' => 'Most fields can only be updated through the Google My Business dashboard',
        ]);

        return false;
    }

    /**
     * Get reviews for a specific location.
     *
     * @param  string  $locationName  Format: locations/{location_id} or accounts/{account_id}/locations/{location_id}
     * @return array|null
     */
    public function getReviews(string $locationName, string $accessToken, ?string $pageToken = null): ?array
    {
        try {
            // Ensure the location name format is correct (accounts/{accountId}/locations/{locationId})
            // If we only have locations/{locationId}, we might need the account ID. 
            // However, the v4 API typically expects accounts/{accountId}/locations/{locationId}/reviews.
            // If the input is just locations/{id}, the API call might fail if we don't prepend the account.
            // But usually, the 'name' stored from GMB API includes the account prefix.

            $url = "https://mybusiness.googleapis.com/v4/{$locationName}/reviews";

            $response = Http::withToken($accessToken)
                ->get($url, [
                    'pageSize' => 50,
                    'pageToken' => $pageToken,
                ]);

            if (!$response->successful()) {
                Log::error('Google My Business API error: Failed to get reviews', [
                    'location_name' => $locationName,
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            Log::error('Google My Business connection error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Reply to a review.
     *
     * @param  string  $reviewName  Format: accounts/{accountId}/locations/{locationId}/reviews/{reviewId}
     */
    public function replyToReview(string $reviewName, string $reply, string $accessToken): bool
    {
        try {
            $url = "https://mybusiness.googleapis.com/v4/{$reviewName}/reply";

            $response = Http::withToken($accessToken)
                ->post($url, [
                    'comment' => $reply,
                ]);

            if (!$response->successful()) {
                Log::error('Google My Business API error: Failed to reply to review', [
                    'review_name' => $reviewName,
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return false;
            }

            return true;
        } catch (ConnectionException $e) {
            Log::error('Google My Business connection error', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get performance insights for a location.
     * 
     * @param string $locationName Format: locations/{locationId}
     * @param string $accessToken
     * @param string $dailyRange (e.g. "2026-01-01")
     */
    public function getPerformanceInsights(string $locationName, string $accessToken, string $startDate, string $endDate): ?array
    {
        try {
            // Note: Performance API uses a different base URL
            $url = "https://businessprofileperformance.googleapis.com/v1/{$locationName}:fetchMultiDailyMetricsTimeSeries";

            $response = Http::withToken($accessToken)
                ->get($url, [
                    'dailyMetrics' => [
                        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
                        'BUSINESS_CONVERSATIONS',
                        'BUSINESS_DIRECTION_REQUESTS',
                        'CALL_CLICKS',
                        'WEBSITE_CLICKS',
                    ],
                    'dailyRange.startDate.year' => date('Y', strtotime($startDate)),
                    'dailyRange.startDate.month' => date('m', strtotime($startDate)),
                    'dailyRange.startDate.day' => date('d', strtotime($startDate)),
                    'dailyRange.endDate.year' => date('Y', strtotime($endDate)),
                    'dailyRange.endDate.month' => date('m', strtotime($endDate)),
                    'dailyRange.endDate.day' => date('d', strtotime($endDate)),
                ]);

            if (!$response->successful()) {
                Log::error('GMB Performance API error', [
                    'location' => $locationName,
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);
                return null;
            }

            return $response->json('multiDailyMetricTimeSeries', []);
        } catch (ConnectionException $e) {
            Log::error('GMB Performance connection error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get Questions for a location.
     */
    public function getQuestions(string $locationName, string $accessToken): ?array
    {
        try {
            // Q&A is part of the v4 My Business API
            $url = "https://mybusiness.googleapis.com/v4/{$locationName}/questions";

            $response = Http::withToken($accessToken)->get($url);

            if (!$response->successful()) {
                Log::error('GMB Q&A API error', ['status' => $response->status()]);
                return null;
            }

            return $response->json('questions', []);
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Answer a question.
     * 
     * @param string $questionName Format: locations/{locId}/questions/{quesId}
     */
    public function answerQuestion(string $questionName, string $answer, string $accessToken): bool
    {
        try {
            $url = "https://mybusiness.googleapis.com/v4/{$questionName}/answers";

            $response = Http::withToken($accessToken)
                ->post($url, [
                    'answer' => ['text' => $answer],
                ]);

            return $response->successful();
        } catch (ConnectionException $e) {
            return false;
        }
    }

    /**
     * Create a Local Post.
     */
    public function createPost(string $locationName, array $postData, string $accessToken): ?array
    {
        try {
            $url = "https://mybusiness.googleapis.com/v4/{$locationName}/localPosts";

            $response = Http::withToken($accessToken)
                ->post($url, [
                    'languageCode' => 'en-US',
                    'summary' => $postData['content'],
                    'callToAction' => [
                        'actionType' => $postData['action_type'] ?? 'LEARN_MORE',
                        'url' => $postData['action_url'] ?? null,
                    ],
                    'topicType' => 'STANDARD',
                ]);

            if (!$response->successful()) {
                Log::error('GMB Post API error', ['error' => $response->json()]);
                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Detect discrepancies between local data and Google data.
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
     * Store Google My Business credentials for a tenant.
     */
    public function storeCredentials(
        Tenant $tenant,
        array $tokenData,
        string $locationName
    ): PlatformCredential {
        $expiresAt = isset($tokenData['expires_in'])
            ? now()->addSeconds($tokenData['expires_in'])
            : null;

        return PlatformCredential::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'platform' => PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS,
                'external_id' => $locationName,
            ],
            [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'token_type' => $tokenData['token_type'] ?? 'Bearer',
                'expires_at' => $expiresAt,
                'scopes' => $this->scopes,
                'metadata' => [
                    'location_name' => $locationName,
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
        return PlatformCredential::getForTenant($tenant, PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS);
    }

    /**
     * Check if tenant has valid Google My Business credentials.
     */
    public function hasValidCredentials(Tenant $tenant): bool
    {
        $credential = $this->getCredentials($tenant);

        return $credential && $credential->isValid();
    }
}
