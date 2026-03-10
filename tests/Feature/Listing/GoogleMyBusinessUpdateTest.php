<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Listing\GoogleMyBusinessService;

/**
 * Integration tests for Google My Business UPDATE operations.
 * These tests make REAL API calls to Google My Business.
 *
 * Prerequisites:
 * 1. Set GOOGLE_GMP_TEST_ACCESS_TOKEN in .env (valid access token)
 * 2. Set GOOGLE_GMP_TEST_LOCATION_NAME in .env (format: locations/{location_id})
 * 3. Ensure you have WRITE permissions on the test location
 *
 * To run: php artisan test tests/Feature/Listing/GoogleMyBusinessUpdateTest.php
 */
describe('Google My Business Location Update', function () {
    beforeEach(function () {
        $this->accessToken = env('GOOGLE_GMP_TEST_ACCESS_TOKEN');
        $this->locationName = env('GOOGLE_GMP_TEST_LOCATION_NAME');

        if (empty($this->accessToken) || empty($this->locationName)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_ACCESS_TOKEN and GOOGLE_GMP_TEST_LOCATION_NAME required');
        }

        $this->service = app(GoogleMyBusinessService::class);
    });

    it('can update business title/name', function () {
        $originalData = $this->service->getLocationDetails($this->locationName, $this->accessToken);
        
        expect($originalData)->toBeArray()
            ->and($originalData)->toHaveKey('title');

        $originalTitle = $originalData['title'];
        $newTitle = 'Test Business ' . time();

        // Update the title
        $result = $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['title' => $newTitle]
        );

        expect($result)->toBeArray()
            ->and($result)->toHaveKey('title')
            ->and($result['title'])->toBe($newTitle);

        // Restore original title
        $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['title' => $originalTitle]
        );
    });

    it('can update website URL', function () {
        $originalData = $this->service->getLocationDetails($this->locationName, $this->accessToken);
        $originalWebsite = $originalData['websiteUri'] ?? null;

        $newWebsite = 'https://example-test-' . time() . '.com';

        $result = $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['websiteUri' => $newWebsite]
        );

        expect($result)->toBeArray()
            ->and($result)->toHaveKey('websiteUri')
            ->and($result['websiteUri'])->toBe($newWebsite);

        // Restore original
        if ($originalWebsite) {
            $this->service->updateLocation(
                $this->locationName,
                $this->accessToken,
                ['websiteUri' => $originalWebsite]
            );
        }
    });

    it('can update phone number', function () {
        $originalData = $this->service->getLocationDetails($this->locationName, $this->accessToken);
        $originalPhone = $originalData['phoneNumbers']['primaryPhone'] ?? null;

        $newPhone = '+1' . rand(2000000000, 9999999999);

        $result = $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            [
                'phoneNumbers' => [
                    'primaryPhone' => $newPhone
                ]
            ]
        );

        expect($result)->toBeArray()
            ->and($result)->toHaveKey('phoneNumbers')
            ->and($result['phoneNumbers']['primaryPhone'])->toBe($newPhone);

        // Restore original
        if ($originalPhone) {
            $this->service->updateLocation(
                $this->locationName,
                $this->accessToken,
                [
                    'phoneNumbers' => [
                        'primaryPhone' => $originalPhone
                    ]
                ]
            );
        }
    });

    it('can update storefront address', function () {
        $originalData = $this->service->getLocationDetails($this->locationName, $this->accessToken);
        $originalAddress = $originalData['storefrontAddress'] ?? null;

        if (!$originalAddress) {
            $this->markTestSkipped('Location has no address to update');
        }

        // Update just the postal code
        $newPostalCode = '90210';
        $updatedAddress = array_merge($originalAddress, [
            'postalCode' => $newPostalCode
        ]);

        $result = $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['storefrontAddress' => $updatedAddress]
        );

        expect($result)->toBeArray()
            ->and($result)->toHaveKey('storefrontAddress')
            ->and($result['storefrontAddress']['postalCode'])->toBe($newPostalCode);

        // Restore original
        $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['storefrontAddress' => $originalAddress]
        );
    });

    it('can update multiple fields at once', function () {
        $originalData = $this->service->getLocationDetails($this->locationName, $this->accessToken);
        
        $timestamp = time();
        $updates = [
            'title' => 'Multi Update Test ' . $timestamp,
            'websiteUri' => 'https://multi-test-' . $timestamp . '.com'
        ];

        $result = $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            $updates
        );

        expect($result)->toBeArray()
            ->and($result['title'])->toBe($updates['title'])
            ->and($result['websiteUri'])->toBe($updates['websiteUri']);

        // Restore originals
        $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            [
                'title' => $originalData['title'],
                'websiteUri' => $originalData['websiteUri'] ?? 'https://example.com'
            ]
        );
    });

    it('handles invalid location ID gracefully', function () {
        $result = $this->service->updateLocation(
            'locations/99999999999',
            $this->accessToken,
            ['title' => 'Test']
        );

        expect($result)->toBeFalse();
    });

    it('handles invalid access token gracefully', function () {
        $result = $this->service->updateLocation(
            $this->locationName,
            'invalid_token_xyz',
            ['title' => 'Test']
        );

        expect($result)->toBeFalse();
    });

    it('handles invalid field names gracefully', function () {
        $result = $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['invalidField' => 'test']
        );

        // Should fail because field doesn't exist
        expect($result)->toBeFalse();
    });

    it('validates required address fields', function () {
        // Address must have regionCode (country code)
        $result = $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            [
                'storefrontAddress' => [
                    'addressLines' => ['123 Test St'],
                    // Missing regionCode - should fail
                ]
            ]
        );

        expect($result)->toBeFalse();
    });

    it('builds correct updateMask for simple fields', function () {
        $data = [
            'title' => 'Test',
            'websiteUri' => 'https://example.com'
        ];

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('buildUpdateMask');
        $method->setAccessible(true);

        $mask = $method->invoke($this->service, $data);

        expect($mask)->toBe('title,websiteUri');
    });

    it('builds correct updateMask for nested fields', function () {
        $data = [
            'title' => 'Test',
            'storefrontAddress' => [
                'addressLines' => ['123 Test'],
                'regionCode' => 'US'
            ],
            'phoneNumbers' => [
                'primaryPhone' => '+15551234567'
            ]
        ];

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('buildUpdateMask');
        $method->setAccessible(true);

        $mask = $method->invoke($this->service, $data);

        expect($mask)->toBe('title,storefrontAddress,phoneNumbers');
    });
});

describe('Google My Business Update with Credential Management', function () {
    beforeEach(function () {
        $this->accessToken = env('GOOGLE_GMP_TEST_ACCESS_TOKEN');
        $this->locationName = env('GOOGLE_GMP_TEST_LOCATION_NAME');

        if (empty($this->accessToken) || empty($this->locationName)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_ACCESS_TOKEN and GOOGLE_GMP_TEST_LOCATION_NAME required');
        }

        $this->service = app(GoogleMyBusinessService::class);
    });

    it('updates location using stored credentials', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Original Name',
            'website' => 'https://original.com',
            'phone' => '+15551234567'
        ]);

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

        // Get original data
        $originalData = $this->service->getLocationDetails($this->locationName, $this->accessToken);

        // Ensure valid token
        $validToken = $this->service->ensureValidToken($credential);
        expect($validToken)->toBeString();

        // Update using the credential
        $result = $this->service->updateLocation(
            $this->locationName,
            $validToken,
            [
                'title' => 'Updated via Credential ' . time()
            ]
        );

        expect($result)->toBeArray()
            ->and($result)->toHaveKey('title');

        // Restore
        $this->service->updateLocation(
            $this->locationName,
            $validToken,
            ['title' => $originalData['title']]
        );
    });

    it('handles expired token during update', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();

        $credential = PlatformCredential::factory()->create([
            'tenant_id' => $tenant->id,
            'platform' => PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS,
            'access_token' => 'expired_token',
            'refresh_token' => null, // No refresh token
            'external_id' => $this->locationName,
            'expires_at' => now()->subHour(), // Expired
            'is_active' => true,
        ]);

        $validToken = $this->service->ensureValidToken($credential);
        
        // Should return null because token is expired and no refresh token
        expect($validToken)->toBeNull();
    });
});

describe('Google My Business Address Update Edge Cases', function () {
    beforeEach(function () {
        $this->accessToken = env('GOOGLE_GMP_TEST_ACCESS_TOKEN');
        $this->locationName = env('GOOGLE_GMP_TEST_LOCATION_NAME');

        if (empty($this->accessToken) || empty($this->locationName)) {
            $this->markTestSkipped('GOOGLE_GMP_TEST_ACCESS_TOKEN and GOOGLE_GMP_TEST_LOCATION_NAME required');
        }

        $this->service = app(GoogleMyBusinessService::class);
    });

    it('can update complete address with all fields', function () {
        $originalData = $this->service->getLocationDetails($this->locationName, $this->accessToken);
        $originalAddress = $originalData['storefrontAddress'] ?? null;

        if (!$originalAddress) {
            $this->markTestSkipped('Location has no address');
        }

        $completeAddress = [
            'regionCode' => 'US',
            'postalCode' => '10001',
            'administrativeArea' => 'NY',
            'locality' => 'New York',
            'addressLines' => [
                '123 Test Street',
                'Suite 456'
            ]
        ];

        $result = $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['storefrontAddress' => $completeAddress]
        );

        expect($result)->toBeArray()
            ->and($result['storefrontAddress']['regionCode'])->toBe('US')
            ->and($result['storefrontAddress']['postalCode'])->toBe('10001')
            ->and($result['storefrontAddress']['locality'])->toBe('New York');

        // Restore
        $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['storefrontAddress' => $originalAddress]
        );
    });

    it('handles address with only required fields', function () {
        $originalData = $this->service->getLocationDetails($this->locationName, $this->accessToken);
        $originalAddress = $originalData['storefrontAddress'] ?? null;

        if (!$originalAddress) {
            $this->markTestSkipped('Location has no address');
        }

        // Minimum required fields
        $minimalAddress = [
            'regionCode' => $originalAddress['regionCode'],
            'addressLines' => $originalAddress['addressLines'] ?? ['123 Main St']
        ];

        $result = $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['storefrontAddress' => $minimalAddress]
        );

        expect($result)->toBeArray()
            ->and($result['storefrontAddress']['regionCode'])->toBe($minimalAddress['regionCode']);

        // Restore
        $this->service->updateLocation(
            $this->locationName,
            $this->accessToken,
            ['storefrontAddress' => $originalAddress]
        );
    });
});
