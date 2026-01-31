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
                ]);
                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Refresh access token using refresh token.
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
            return null;
        }
    }

    /**
     * Ensure the credential has a valid access token, refreshing if necessary.
     */
    public function ensureValidToken(PlatformCredential $credential): ?string
    {
        if (!$credential->isExpired()) {
            return $credential->access_token;
        }

        if (!$credential->refresh_token) {
            Log::warning('Google Credential expired and no refresh token available', ['id' => $credential->id]);
            return null;
        }

        $newTokenData = $this->refreshAccessToken($credential->refresh_token);

        if (!$newTokenData || !isset($newTokenData['access_token'])) {
            Log::error('Failed to refresh Google access token', ['id' => $credential->id]);
            return null;
        }

        $credential->update([
            'access_token' => $newTokenData['access_token'],
            'expires_at' => now()->addSeconds($newTokenData['expires_in'] ?? 3599),
        ]);

        return $credential->access_token;
    }

    /**
     * Get all business accounts the user has access to.
     */
    public function getAccounts(string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

            return $response->successful() ? $response->json('accounts', []) : null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Get all locations for a business account.
     */
    public function getLocations(string $accountId, string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$accountId}/locations", [
                    'readMask' => 'name,title,storefrontAddress,phoneNumbers,websiteUri,categories,regularHours,specialHours,serviceArea,labels,latlng,openInfo,metadata,profile',
                ]);

            return $response->successful() ? $response->json('locations', []) : null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Get detailed information for a specific location.
     */
    public function getLocationDetails(string $locationName, string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$locationName}", [
                    'readMask' => 'name,title,storefrontAddress,phoneNumbers,websiteUri,categories,regularHours,specialHours,serviceArea,labels,adWordsLocationExtensions,latlng,openInfo,metadata,profile,relationshipData',
                ]);

            return $response->successful() ? $response->json() : null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Sync a location's listing from Google My Business.
     */
    public function syncListing(Location $location, PlatformCredential $credential): ?Listing
    {
        $accessToken = $this->ensureValidToken($credential);
        if (!$accessToken) return null;

        $locationName = $credential->metadata['location_name'] ?? $credential->external_id;

        if (!$locationName) {
            Log::error('No Google My Business location name configured', ['tenant_id' => $credential->tenant_id]);
            return null;
        }

        $locationData = $this->getLocationDetails($locationName, $accessToken);
        if (!$locationData) return null;

        // Fetch Photos
        $media = $this->getLocationMedia($locationName, $accessToken);

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
                'business_hours' => [
                    'regular' => $locationData['regularHours'] ?? null,
                    'special' => $locationData['specialHours'] ?? null,
                ],
                'latitude' => $locationData['latlng']['latitude'] ?? null,
                'longitude' => $locationData['latlng']['longitude'] ?? null,
                'photos' => $media ?? [],
                'attributes' => [
                    'labels' => $locationData['labels'] ?? null,
                    'service_area' => $locationData['serviceArea'] ?? null,
                    'open_info' => $locationData['openInfo'] ?? null,
                    'metadata' => $locationData['metadata'] ?? null,
                    'profile' => $locationData['profile'] ?? null,
                ],
                'last_synced_at' => now(),
            ]
        );

        $discrepancies = $this->detectDiscrepancies($location, $listing);
        if (!empty($discrepancies)) {
            $listing->setDiscrepancies($discrepancies);
        }

        return $listing;
    }

    /**
     * Get reviews for a specific location.
     */
    public function getReviews(string $locationName, string $accessToken, ?string $pageToken = null): ?array
    {
        try {
            $url = "https://mybusiness.googleapis.com/v4/{$locationName}/reviews";
            $response = Http::withToken($accessToken)->get($url, ['pageSize' => 50, 'pageToken' => $pageToken]);
            return $response->successful() ? $response->json() : null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Reply to a review.
     */
    public function replyToReview(string $reviewName, string $reply, string $accessToken): bool
    {
        try {
            $url = "https://mybusiness.googleapis.com/v4/{$reviewName}/reply";
            
            Log::info('Attempting to reply to Google review', [
                'url' => $url,
                'reviewName' => $reviewName,
                'reply' => $reply,
            ]);
            
            // Google My Business API requires PUT method for updateReply
            $response = Http::withToken($accessToken)->put($url, [
                'comment' => $reply
            ]);
            
            if (!$response->successful()) {
                Log::error('Failed to reply to Google review', [
                    'status' => $response->status(),
                    'error' => $response->json(),
                    'body' => $response->body(),
                ]);
                return false;
            }
            
            Log::info('Successfully replied to Google review', [
                'response' => $response->json(),
            ]);
            
            return true;
        } catch (ConnectionException $e) {
            Log::error('Connection error replying to Google review', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get performance insights for a location.
     */
    public function getPerformanceInsights(string $locationName, string $accessToken, string $startDate, string $endDate): ?array
    {
        try {
            $url = "https://businessprofileperformance.googleapis.com/v1/{$locationName}:fetchMultiDailyMetricsTimeSeries";
            $response = Http::withToken($accessToken)->get($url, [
                'dailyMetrics' => ['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'BUSINESS_CONVERSATIONS', 'BUSINESS_DIRECTION_REQUESTS', 'CALL_CLICKS', 'WEBSITE_CLICKS'],
                'dailyRange.startDate.year' => date('Y', strtotime($startDate)), 'dailyRange.startDate.month' => date('m', strtotime($startDate)), 'dailyRange.startDate.day' => date('d', strtotime($startDate)),
                'dailyRange.endDate.year' => date('Y', strtotime($endDate)), 'dailyRange.endDate.month' => date('m', strtotime($endDate)), 'dailyRange.endDate.day' => date('d', strtotime($endDate)),
            ]);
            return $response->successful() ? $response->json('multiDailyMetricTimeSeries', []) : null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Get Questions for a location.
     */
    public function getQuestions(string $locationName, string $accessToken): ?array
    {
        try {
            $url = "https://mybusiness.googleapis.com/v4/{$locationName}/questions";
            $response = Http::withToken($accessToken)->get($url);
            return $response->successful() ? $response->json('questions', []) : null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Answer a question.
     */
    public function answerQuestion(string $questionName, string $answer, string $accessToken): bool
    {
        try {
            $url = "https://mybusiness.googleapis.com/v4/{$questionName}/answers";
            $response = Http::withToken($accessToken)->post($url, ['answer' => ['text' => $answer]]);
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
            $response = Http::withToken($accessToken)->post($url, [
                'languageCode' => 'en-US',
                'summary' => $postData['content'],
                'callToAction' => ['actionType' => $postData['action_type'] ?? 'LEARN_MORE', 'url' => $postData['action_url'] ?? null],
                'topicType' => 'STANDARD',
            ]);
            return $response->successful() ? $response->json() : null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Get all media items (photos/videos) for a location.
     */
    public function getLocationMedia(string $locationName, string $accessToken): ?array
    {
        try {
            $url = "https://mybusiness.googleapis.com/v4/{$locationName}/media";
            $response = Http::withToken($accessToken)->get($url);
            return $response->successful() ? $response->json('mediaItems', []) : null;
        } catch (ConnectionException $e) {
            return null;
        }
    }

    /**
     * Upload a photo to a location.
     */
    public function uploadMedia(string $locationName, string $mediaUrl, string $accessToken, string $category = 'ADDITIONAL'): ?array
    {
        try {
            $url = "https://businessprofile.googleapis.com/v1/{$locationName}/media";

            $response = Http::withToken($accessToken)
                ->post($url, [
                    'mediaFormat' => 'PHOTO',
                    'sourceUrl' => $mediaUrl,
                    'locationAssociation' => [
                        'category' => $category
                    ]
                ]);

            Log::info('GMB Media upload response', [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);

            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::error('GMB Media upload error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Detect discrepancies between local data and Google data.
     */
    protected function detectDiscrepancies(Location $location, Listing $listing): array
    {
        $discrepancies = [];
        $fields = ['name' => 'name', 'phone' => 'phone', 'website' => 'website', 'address' => 'address', 'city' => 'city', 'state' => 'state', 'postal_code' => 'postal_code'];

        foreach ($fields as $locationField => $listingField) {
            $localValue = $location->{$locationField};
            $platformValue = $listing->{$listingField};
            if ($localValue && $platformValue && strtolower(trim((string)$localValue)) !== strtolower(trim((string)$platformValue))) {
                $discrepancies[$locationField] = ['local' => $localValue, 'platform' => $platformValue];
            }
        }
        return $discrepancies;
    }

    /**
     * Store Google My Business credentials for a tenant.
     */
    public function storeCredentials(Tenant $tenant, array $tokenData, string $locationName): PlatformCredential
    {
        $expiresAt = isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null;
        return PlatformCredential::updateOrCreate(
            ['tenant_id' => $tenant->id, 'platform' => PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS, 'external_id' => $locationName],
            ['access_token' => $tokenData['access_token'], 'refresh_token' => $tokenData['refresh_token'] ?? null, 'token_type' => $tokenData['token_type'] ?? 'Bearer', 'expires_at' => $expiresAt, 'scopes' => $this->scopes, 'metadata' => ['location_name' => $locationName], 'is_active' => true]
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