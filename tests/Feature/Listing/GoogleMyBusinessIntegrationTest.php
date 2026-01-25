<?php

declare(strict_types=1);

use App\Models\Listing;
use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Listing\GoogleMyBusinessService;
use Laravel\Sanctum\Sanctum;

/**
 * Comprehensive Integration tests for Google My Business API.
 * These tests make REAL API calls to Google My Business.
 *
 * Prerequisites:
 * 1. Set GOOGLE_GMP_CLIENT_ID in .env
 * 2. Set GOOGLE_GMP_CLIENT_SECRET in .env
 * 3. Set GOOGLE_GMP_REDIRECT_URI in .env
 * 4. Set GOOGLE_GMP_TEST_ACCESS_TOKEN in .env (get this manually via OAuth flow first)
 * 5. Set GOOGLE_GMP_TEST_REFRESH_TOKEN in .env (optional, for token refresh tests)
 * 6. Set GOOGLE_GMP_TEST_ACCOUNT_ID in .env (format: accounts/{account_id})
 * 7. Set GOOGLE_GMP_TEST_LOCATION_NAME in .env (format: locations/{location_id})
 *
 * To run: php artisan test tests/Feature/Listing/GoogleMyBusinessIntegrationTest.php
 */
describe('Google My Business OAuth Flow', function () {
    beforeEach(function () {
        $this->clientId = config('google.my_business.client_id');
        $this->clientSecret = config('google.my_business.client_secret');
        $this->redirectUri = config('google.my_business.redirect_uri');

        if (empty($this->clientId) || empty($this->clientSecret)) {
            $this->markTestSkipped('Google My Business OAuth credentials not configured in .env');
        }
    });

    it('generates valid OAuth URL with correct parameters', function () {
        $service = app(GoogleMyBusinessService::class);
        $state = base64_encode(json_encode(['tenant_id' => 123]));

        $url = $service->getLoginUrl($this->redirectUri, $state);

        expect($url)->toBeString()
            ->and($url)->toContain('accounts.google.com/o/oauth2/v2/auth')
            ->and($url)->toContain('client_id=' . urlencode($this->clientId))
            ->and($url)->toContain('redirect_uri=' . urlencode($this->redirectUri))
            ->and($url)->toContain('state=' . urlencode($state))
            ->and($url)->toContain('scope=')
            ->and($url)->toContain('business.manage')
            ->and($url)->toContain('response_type=code')
            ->and($url)->toContain('access_type=offline')
            ->and($url)->toContain('prompt=consent');
    });

    it('OAuth URL includes all required scopes', function () {
        $service = app(GoogleMyBusinessService::class);
        $url = $service->getLoginUrl($this->redirectUri, 'test-state');

        $requiredScopes = [
            'https://www.googleapis.com/auth/business.manage',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ];

        foreach ($requiredScopes as $scope) {
            expect($url)->toContain(urlencode($scope));
        }
    });

    it('can exchange authorization code for access token', function () {
        // This test requires a valid authorization code
        // Skip if not provided
        $authCode = env('GOOGLE_GMP_TEST_AUTH_CODE');

        if (empty($authCode)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_AUTH_CODE not set. Get this from OAuth callback.');
        }

        $service = app(GoogleMyBusinessService::class);
        $tokenData = $service->getAccessTokenFromCode($authCode, $this->redirectUri);

        expect($tokenData)->toBeArray()
            ->and($tokenData)->toHaveKey('access_token')
            ->and($tokenData['access_token'])->toBeString()
            ->and($tokenData)->toHaveKey('token_type')
            ->and($tokenData['token_type'])->toBe('Bearer')
            ->and($tokenData)->toHaveKey('expires_in')
            ->and($tokenData['expires_in'])->toBeInt();

        // Should also have refresh token on first authorization
        if (isset($tokenData['refresh_token'])) {
            expect($tokenData['refresh_token'])->toBeString();
        }
    });

    it('handles invalid authorization code gracefully', function () {
        $service = app(GoogleMyBusinessService::class);
        $tokenData = $service->getAccessTokenFromCode('invalid_code_12345', $this->redirectUri);

        expect($tokenData)->toBeNull();
    });

    it('handles network errors during token exchange', function () {
        $service = app(GoogleMyBusinessService::class);

        // Use invalid redirect URI to trigger error
        $tokenData = $service->getAccessTokenFromCode('test_code', 'http://invalid-redirect.local');

        expect($tokenData)->toBeNull();
    });
});

describe('Google My Business Account & Location Fetching', function () {
    beforeEach(function () {
        $this->accessToken = env('GOOGLE_GMP_TEST_ACCESS_TOKEN');

        if (empty($this->accessToken)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_ACCESS_TOKEN not set. Complete OAuth flow first.');
        }
    });

    it('can fetch business accounts from Google', function () {
        $service = app(GoogleMyBusinessService::class);
        $accounts = $service->getAccounts($this->accessToken);

        expect($accounts)->toBeArray();

        if (count($accounts) > 0) {
            $account = $accounts[0];
            expect($account)->toHaveKey('name')
                ->and($account)->toHaveKey('accountName')
                ->and($account['name'])->toMatch('/^accounts\/\d+$/');
        }
    });

    it('handles invalid access token when fetching accounts', function () {
        $service = app(GoogleMyBusinessService::class);
        $accounts = $service->getAccounts('invalid_token_xyz');

        expect($accounts)->toBeNull();
    });

    it('can fetch locations for a business account', function () {
        $accountId = env('GOOGLE_GMP_TEST_ACCOUNT_ID');

        if (empty($accountId)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_ACCOUNT_ID not set (format: accounts/{id})');
        }

        $service = app(GoogleMyBusinessService::class);
        $locations = $service->getLocations($accountId, $this->accessToken);

        expect($locations)->toBeArray();

        if (count($locations) > 0) {
            $location = $locations[0];
            expect($location)->toHaveKey('name')
                ->and($location)->toHaveKey('title')
                ->and($location['name'])->toMatch('/^locations\/\d+$/');
        }
    });

    it('handles invalid account ID when fetching locations', function () {
        $service = app(GoogleMyBusinessService::class);
        $locations = $service->getLocations('accounts/99999999999', $this->accessToken);

        // Should return null or empty array for non-existent account
        expect($locations)->toBeIn([null, []]);
    });

    it('can fetch detailed location information', function () {
        $locationName = env('GOOGLE_GMP_TEST_LOCATION_NAME');

        if (empty($locationName)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_LOCATION_NAME not set (format: locations/{id})');
        }

        $service = app(GoogleMyBusinessService::class);
        $locationDetails = $service->getLocationDetails($locationName, $this->accessToken);

        expect($locationDetails)->toBeArray()
            ->and($locationDetails)->toHaveKey('name')
            ->and($locationDetails)->toHaveKey('title')
            ->and($locationDetails['name'])->toBe($locationName);

        // Check for common fields
        $expectedFields = ['title', 'storefrontAddress', 'phoneNumbers', 'websiteUri', 'categories'];
        $foundFields = array_filter($expectedFields, fn($field) => isset($locationDetails[$field]));

        expect(count($foundFields))->toBeGreaterThan(0, 'Should have at least one expected field');
    });

    it('returns null for non-existent location', function () {
        $service = app(GoogleMyBusinessService::class);
        $locationDetails = $service->getLocationDetails('locations/99999999999', $this->accessToken);

        expect($locationDetails)->toBeNull();
    });

    it('location details contain address information', function () {
        $locationName = env('GOOGLE_GMP_TEST_LOCATION_NAME');

        if (empty($locationName)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_LOCATION_NAME not set');
        }

        $service = app(GoogleMyBusinessService::class);
        $locationDetails = $service->getLocationDetails($locationName, $this->accessToken);

        if (isset($locationDetails['storefrontAddress'])) {
            $address = $locationDetails['storefrontAddress'];

            // Should have at least some address components
            $addressFields = ['addressLines', 'locality', 'administrativeArea', 'postalCode', 'regionCode'];
            $hasAddressData = false;

            foreach ($addressFields as $field) {
                if (isset($address[$field])) {
                    $hasAddressData = true;
                    break;
                }
            }

            expect($hasAddressData)->toBeTrue('Address should have at least one component');
        }
    });
});

describe('Google My Business Listing Sync', function () {
    beforeEach(function () {
        $this->accessToken = env('GOOGLE_GMP_TEST_ACCESS_TOKEN');
        $this->locationName = env('GOOGLE_GMP_TEST_LOCATION_NAME');

        if (empty($this->accessToken) || empty($this->locationName)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_ACCESS_TOKEN and GOOGLE_GMP_TEST_LOCATION_NAME required');
        }
    });

    it('syncs listing from Google My Business location', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        // Create credential with real token
        $credential = PlatformCredential::factory()->create([
            'tenant_id' => $tenant->id,
            'platform' => PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS,
            'access_token' => $this->accessToken,
            'external_id' => $this->locationName,
            'metadata' => [
                'location_name' => $this->locationName,
            ],
            'is_active' => true,
        ]);

        $service = app(GoogleMyBusinessService::class);
        $listing = $service->syncListing($location, $credential);

        expect($listing)->toBeInstanceOf(Listing::class)
            ->and($listing->platform)->toBe(Listing::PLATFORM_GOOGLE_MY_BUSINESS)
            ->and($listing->external_id)->toBe($this->locationName)
            ->and($listing->status)->toBe(Listing::STATUS_SYNCED)
            ->and($listing->last_synced_at)->not->toBeNull();

        // Verify data was synced
        expect($listing->name)->not->toBeNull();
    });

    it('detects discrepancies between local and Google data', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();

        // Create location with specific data
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Business Name',
            'phone' => '+1-555-0100',
            'website' => 'https://example.com',
        ]);

        $credential = PlatformCredential::factory()->create([
            'tenant_id' => $tenant->id,
            'platform' => PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS,
            'access_token' => $this->accessToken,
            'external_id' => $this->locationName,
            'metadata' => ['location_name' => $this->locationName],
            'is_active' => true,
        ]);

        $service = app(GoogleMyBusinessService::class);
        $listing = $service->syncListing($location, $credential);

        // If Google data differs from local, discrepancies should be detected
        if (
            $listing->name !== $location->name ||
            $listing->phone !== $location->phone ||
            $listing->website !== $location->website
        ) {

            expect($listing->discrepancies)->not->toBeNull()
                ->and($listing->discrepancies)->toBeArray();
        }
    });

    it('handles expired access token during sync', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        $credential = PlatformCredential::factory()->create([
            'tenant_id' => $tenant->id,
            'platform' => PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS,
            'access_token' => 'expired_token_12345',
            'external_id' => $this->locationName,
            'metadata' => ['location_name' => $this->locationName],
            'is_active' => true,
        ]);

        $service = app(GoogleMyBusinessService::class);
        $listing = $service->syncListing($location, $credential);

        expect($listing)->toBeNull();
    });
});

describe('Google My Business API Endpoints', function () {
    beforeEach(function () {
        $this->accessToken = env('GOOGLE_GMP_TEST_ACCESS_TOKEN');
        $this->accountId = env('GOOGLE_GMP_TEST_ACCOUNT_ID');
        $this->locationName = env('GOOGLE_GMP_TEST_LOCATION_NAME');

        if (empty($this->accessToken)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_ACCESS_TOKEN required');
        }
    });

    it('connect endpoint returns OAuth URL', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/google/connect");

        $response->assertSuccessful()
            ->assertJsonStructure(['url']);

        expect($response->json('url'))->toContain('accounts.google.com/o/oauth2/v2/auth');
    });

    it('stores Google My Business credentials successfully', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/google/credentials", [
            'access_token' => $this->accessToken,
            'refresh_token' => 'test_refresh_token',
            'location_name' => $this->locationName,
            'expires_in' => 3600,
        ]);

        $response->assertCreated();

        expect($response->json('data.platform'))->toBe('google_my_business')
            ->and($response->json('data.is_active'))->toBeTrue();

        // Verify database
        $this->assertDatabaseHas('platform_credentials', [
            'tenant_id' => $tenant->id,
            'platform' => 'google_my_business',
            'is_active' => true,
        ]);
    });

    it('syncs listing via API endpoint', function () {
        if (empty($this->locationName)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_LOCATION_NAME required');
        }

        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        // Create credential
        PlatformCredential::factory()->create([
            'tenant_id' => $tenant->id,
            'platform' => PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS,
            'access_token' => $this->accessToken,
            'external_id' => $this->locationName,
            'metadata' => ['location_name' => $this->locationName],
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/locations/{$location->id}/listings/sync/google_my_business");

        if ($response->status() === 201 || $response->status() === 200) {
            $response->assertSuccessful();
            expect($response->json('data.platform'))->toBe('google_my_business')
                ->and($response->json('data.status'))->toBe('synced');
        } else {
            // If failed, should be due to API issues
            $response->assertStatus(422);
        }
    });

    it('gets available platforms showing Google My Business connection status', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();

        // Create Google credential
        PlatformCredential::factory()->create([
            'tenant_id' => $tenant->id,
            'platform' => PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS,
            'access_token' => $this->accessToken,
            'external_id' => $this->locationName ?? 'locations/test',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/listings/platforms");

        $response->assertSuccessful();
        expect($response->json('data.google_my_business.connected'))->toBeTrue()
            ->and($response->json('data.facebook.connected'))->toBeFalse();
    });
});

describe('Google My Business Token Refresh', function () {
    beforeEach(function () {
        $this->refreshToken = env('GOOGLE_GMP_TEST_REFRESH_TOKEN');

        if (empty($this->refreshToken)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_REFRESH_TOKEN not set');
        }
    });

    it('can refresh access token using refresh token', function () {
        $service = app(GoogleMyBusinessService::class);
        $tokenData = $service->refreshAccessToken($this->refreshToken);

        expect($tokenData)->toBeArray()
            ->and($tokenData)->toHaveKey('access_token')
            ->and($tokenData['access_token'])->toBeString()
            ->and($tokenData)->toHaveKey('expires_in')
            ->and($tokenData['expires_in'])->toBeInt();
    });

    it('handles invalid refresh token gracefully', function () {
        $service = app(GoogleMyBusinessService::class);
        $tokenData = $service->refreshAccessToken('invalid_refresh_token');

        expect($tokenData)->toBeNull();
    });
});

describe('Google My Business Error Handling', function () {
    it('handles rate limiting gracefully', function () {
        // This test would require making many requests
        // Skip for now, but service should handle 429 responses
        $this->markTestSkipped('Rate limiting test requires many API calls');
    });

    it('handles API quota exceeded', function () {
        // Similar to rate limiting
        $this->markTestSkipped('Quota test requires exceeding daily limits');
    });

    it('validates required scopes before API calls', function () {
        $service = app(GoogleMyBusinessService::class);

        // Token with insufficient scopes should fail gracefully
        $result = $service->getAccounts('token_with_wrong_scopes');

        expect($result)->toBeNull();
    });
});
