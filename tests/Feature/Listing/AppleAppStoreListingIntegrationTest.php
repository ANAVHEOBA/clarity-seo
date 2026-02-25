<?php

declare(strict_types=1);

use App\Models\AppleAppStoreAccount;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

const APPLE_TEST_PRIVATE_KEY_LISTING = <<<'KEY'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIDorL4c3Wu+B8BbiBxErio91K/b4p6J+2479nu/2rLoboAoGCCqGSM49
AwEHoUQDQgAE1V9v121umEW3LFAMj8W/bj8OxkW0x+ym1UNuW0Ng6A6ekXCqYFWp
QvvJ/jpTeFc5q7y36GPkhahmh9aOSY7gIA==
-----END EC PRIVATE KEY-----
KEY;

describe('Apple App Store listing integration', function () {
    it('syncs listing from app store app endpoint', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'apple_app_store_app_id' => '6758574978',
        ]);

        AppleAppStoreAccount::create([
            'tenant_id' => $tenant->id,
            'name' => 'Apple Key',
            'issuer_id' => '3d24e14c-e344-4c39-bd2d-7dd0c414f476',
            'key_id' => '645X2P2WBB',
            'private_key' => APPLE_TEST_PRIVATE_KEY_LISTING,
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.appstoreconnect.apple.com/v1/apps/6758574978*' => Http::response([
                'data' => [
                    'type' => 'apps',
                    'id' => '6758574978',
                    'attributes' => [
                        'name' => 'Quietarc',
                        'bundleId' => 'com.innovatedagency.quiettime',
                        'sku' => 'EX1769954004313',
                        'primaryLocale' => 'en-US',
                        'isOrEverWasMadeForKids' => false,
                        'contentRightsDeclaration' => 'DOES_NOT_USE_THIRD_PARTY_CONTENT',
                        'streamlinedPurchasingEnabled' => true,
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/locations/{$location->id}/listings/sync/apple_app_store");

        $response->assertOk();
        $response->assertJsonPath('data.platform', 'apple_app_store');
        $response->assertJsonPath('data.name', 'Quietarc');

        $this->assertDatabaseHas('listings', [
            'location_id' => $location->id,
            'platform' => 'apple_app_store',
            'external_id' => '6758574978',
            'name' => 'Quietarc',
            'status' => 'synced',
        ]);
    });

    it('publishes listing for apple app store platform', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'apple_app_store_app_id' => '6758574978',
        ]);

        AppleAppStoreAccount::create([
            'tenant_id' => $tenant->id,
            'name' => 'Apple Key',
            'issuer_id' => '3d24e14c-e344-4c39-bd2d-7dd0c414f476',
            'key_id' => '645X2P2WBB',
            'private_key' => APPLE_TEST_PRIVATE_KEY_LISTING,
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.appstoreconnect.apple.com/v1/apps/6758574978*' => Http::response([
                'data' => [
                    'type' => 'apps',
                    'id' => '6758574978',
                    'attributes' => [
                        'name' => 'Quietarc',
                        'bundleId' => 'com.innovatedagency.quiettime',
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/locations/{$location->id}/listings/publish/apple_app_store");

        $response->assertOk();

        $listing = Listing::where('location_id', $location->id)
            ->where('platform', 'apple_app_store')
            ->first();

        expect($listing)->not->toBeNull();
        expect($listing->last_published_at)->not->toBeNull();
    });
});
