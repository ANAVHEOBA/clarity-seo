<?php

declare(strict_types=1);

use App\Services\Listing\GoogleMyBusinessService;
use Illuminate\Support\Facades\Http;

/**
 * Unit tests for Google My Business UPDATE operations using HTTP mocks.
 * These tests do NOT make real API calls - they use mocked responses.
 * 
 * To run: php artisan test tests/Unit/GoogleMyBusinessUpdateTest.php
 */
describe('Google My Business Update Location (Mocked)', function () {
    beforeEach(function () {
        // Mock config values
        config([
            'google.my_business.client_id' => 'test_client_id',
            'google.my_business.client_secret' => 'test_client_secret',
            'google.my_business.scopes' => [
                'https://www.googleapis.com/auth/business.manage',
            ],
        ]);
        
        $this->service = app(GoogleMyBusinessService::class);
        $this->accessToken = 'mock_access_token_12345';
        $this->locationId = 'locations/1234567890';
    });

    it('successfully updates business title', function () {
        // Mock successful response
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'name' => $this->locationId,
                'title' => 'Updated Business Name',
                'websiteUri' => 'https://example.com',
            ], 200)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            ['title' => 'Updated Business Name']
        );

        expect($result)->toBeArray()
            ->and($result['title'])->toBe('Updated Business Name')
            ->and($result['name'])->toBe($this->locationId);

        // Verify the request was made correctly
        Http::assertSent(function ($request) {
            return $request->url() === "https://mybusinessbusinessinformation.googleapis.com/v1/{$this->locationId}?updateMask=title"
                && $request->method() === 'PATCH'
                && $request->hasHeader('Authorization', 'Bearer ' . $this->accessToken)
                && $request['title'] === 'Updated Business Name';
        });
    });

    it('successfully updates website URL', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'name' => $this->locationId,
                'title' => 'My Business',
                'websiteUri' => 'https://newwebsite.com',
            ], 200)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            ['websiteUri' => 'https://newwebsite.com']
        );

        expect($result)->toBeArray()
            ->and($result['websiteUri'])->toBe('https://newwebsite.com');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'updateMask=websiteUri')
                && $request['websiteUri'] === 'https://newwebsite.com';
        });
    });

    it('successfully updates phone number', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'name' => $this->locationId,
                'phoneNumbers' => [
                    'primaryPhone' => '+15551234567'
                ],
            ], 200)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            [
                'phoneNumbers' => [
                    'primaryPhone' => '+15551234567'
                ]
            ]
        );

        expect($result)->toBeArray()
            ->and($result['phoneNumbers']['primaryPhone'])->toBe('+15551234567');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'updateMask=phoneNumbers')
                && $request['phoneNumbers']['primaryPhone'] === '+15551234567';
        });
    });

    it('successfully updates storefront address', function () {
        $newAddress = [
            'regionCode' => 'US',
            'postalCode' => '90210',
            'administrativeArea' => 'CA',
            'locality' => 'Beverly Hills',
            'addressLines' => ['123 Main St', 'Suite 100']
        ];

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'name' => $this->locationId,
                'storefrontAddress' => $newAddress,
            ], 200)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            ['storefrontAddress' => $newAddress]
        );

        expect($result)->toBeArray()
            ->and($result['storefrontAddress']['regionCode'])->toBe('US')
            ->and($result['storefrontAddress']['postalCode'])->toBe('90210')
            ->and($result['storefrontAddress']['locality'])->toBe('Beverly Hills');

        Http::assertSent(function ($request) use ($newAddress) {
            return str_contains($request->url(), 'updateMask=storefrontAddress')
                && $request['storefrontAddress']['regionCode'] === 'US'
                && $request['storefrontAddress']['postalCode'] === '90210';
        });
    });

    it('successfully updates multiple fields at once', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'name' => $this->locationId,
                'title' => 'New Business Name',
                'websiteUri' => 'https://newsite.com',
                'phoneNumbers' => [
                    'primaryPhone' => '+15559876543'
                ],
            ], 200)
        ]);

        $updates = [
            'title' => 'New Business Name',
            'websiteUri' => 'https://newsite.com',
            'phoneNumbers' => [
                'primaryPhone' => '+15559876543'
            ]
        ];

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            $updates
        );

        expect($result)->toBeArray()
            ->and($result['title'])->toBe('New Business Name')
            ->and($result['websiteUri'])->toBe('https://newsite.com')
            ->and($result['phoneNumbers']['primaryPhone'])->toBe('+15559876543');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'updateMask=title,websiteUri,phoneNumbers');
        });
    });

    it('handles 404 not found error', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 404,
                    'message' => 'Location not found',
                    'status' => 'NOT_FOUND'
                ]
            ], 404)
        ]);

        $result = $this->service->updateLocation(
            'locations/99999999999',
            $this->accessToken,
            ['title' => 'Test']
        );

        expect($result)->toBeFalse();
    });

    it('handles 401 unauthorized error', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 401,
                    'message' => 'Invalid credentials',
                    'status' => 'UNAUTHENTICATED'
                ]
            ], 401)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            'invalid_token',
            ['title' => 'Test']
        );

        expect($result)->toBeFalse();
    });

    it('handles 403 permission denied error', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'Permission denied',
                    'status' => 'PERMISSION_DENIED'
                ]
            ], 403)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            ['title' => 'Test']
        );

        expect($result)->toBeFalse();
    });

    it('handles 400 bad request error', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'Invalid field value',
                    'status' => 'INVALID_ARGUMENT'
                ]
            ], 400)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            ['invalidField' => 'test']
        );

        expect($result)->toBeFalse();
    });

    it('handles network timeout', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
            }
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            ['title' => 'Test']
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

    it('builds correct updateMask with single field', function () {
        $data = ['title' => 'Test'];

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('buildUpdateMask');
        $method->setAccessible(true);

        $mask = $method->invoke($this->service, $data);

        expect($mask)->toBe('title');
    });

    it('sends correct HTTP headers', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'name' => $this->locationId,
                'title' => 'Test',
            ], 200)
        ]);

        $accessToken = $this->accessToken; // Capture for closure

        $this->service->updateLocation(
            $this->locationId,
            $accessToken,
            ['title' => 'Test']
        );

        Http::assertSent(function ($request) use ($accessToken) {
            return $request->hasHeader('Authorization', 'Bearer ' . $accessToken)
                && $request->method() === 'PATCH';
        });
    });

    it('constructs correct API URL with updateMask', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'name' => $this->locationId,
            ], 200)
        ]);

        $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            ['title' => 'Test', 'websiteUri' => 'https://example.com']
        );

        Http::assertSent(function ($request) {
            $expectedUrl = "https://mybusinessbusinessinformation.googleapis.com/v1/{$this->locationId}?updateMask=title,websiteUri";
            return $request->url() === $expectedUrl;
        });
    });

    it('handles empty update data gracefully', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'updateMask is required',
                ]
            ], 400)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            []
        );

        expect($result)->toBeFalse();
    });
});

describe('Google My Business Update - Response Validation', function () {
    beforeEach(function () {
        // Mock config values
        config([
            'google.my_business.client_id' => 'test_client_id',
            'google.my_business.client_secret' => 'test_client_secret',
            'google.my_business.scopes' => [
                'https://www.googleapis.com/auth/business.manage',
            ],
        ]);
        
        $this->service = app(GoogleMyBusinessService::class);
        $this->accessToken = 'mock_access_token';
        $this->locationId = 'locations/1234567890';
    });

    it('returns complete location data after update', function () {
        $completeResponse = [
            'name' => $this->locationId,
            'title' => 'Updated Business',
            'websiteUri' => 'https://example.com',
            'phoneNumbers' => [
                'primaryPhone' => '+15551234567',
                'additionalPhones' => ['+15559876543']
            ],
            'storefrontAddress' => [
                'regionCode' => 'US',
                'postalCode' => '12345',
                'administrativeArea' => 'NY',
                'locality' => 'New York',
                'addressLines' => ['123 Main St']
            ],
            'categories' => [
                [
                    'name' => 'categories/gcid:restaurant',
                    'displayName' => 'Restaurant'
                ]
            ],
            'metadata' => [
                'mapsUri' => 'https://maps.google.com/?cid=123',
                'newReviewUri' => 'https://search.google.com/local/writereview?placeid=123'
            ]
        ];

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response($completeResponse, 200)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            ['title' => 'Updated Business']
        );

        expect($result)->toBeArray()
            ->and($result)->toHaveKey('name')
            ->and($result)->toHaveKey('title')
            ->and($result)->toHaveKey('websiteUri')
            ->and($result)->toHaveKey('phoneNumbers')
            ->and($result)->toHaveKey('storefrontAddress')
            ->and($result['title'])->toBe('Updated Business');
    });

    it('handles partial response data', function () {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'name' => $this->locationId,
                'title' => 'Minimal Business',
            ], 200)
        ]);

        $result = $this->service->updateLocation(
            $this->locationId,
            $this->accessToken,
            ['title' => 'Minimal Business']
        );

        expect($result)->toBeArray()
            ->and($result['name'])->toBe($this->locationId)
            ->and($result['title'])->toBe('Minimal Business')
            ->and($result)->not->toHaveKey('websiteUri');
    });
});
