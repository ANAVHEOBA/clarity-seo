<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

const APPLE_TEST_PRIVATE_KEY = <<<'KEY'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIDorL4c3Wu+B8BbiBxErio91K/b4p6J+2479nu/2rLoboAoGCCqGSM49
AwEHoUQDQgAE1V9v121umEW3LFAMj8W/bj8OxkW0x+ym1UNuW0Ng6A6ekXCqYFWp
QvvJ/jpTeFc5q7y36GPkhahmh9aOSY7gIA==
-----END EC PRIVATE KEY-----
KEY;

describe('Apple App Store integration', function () {
    it('stores App Store account credentials for a tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();

        Sanctum::actingAs($user);

        $payload = [
            'name' => 'LocalClarity API key',
            'issuer_id' => '3d24e14c-e344-4c39-bd2d-7dd0c414f476',
            'key_id' => '365CZB3ST7',
            'private_key' => APPLE_TEST_PRIVATE_KEY,
        ];

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/apple-app-store/accounts", $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'LocalClarity API key')
            ->assertJsonPath('data.issuer_id', '3d24e14c-e344-4c39-bd2d-7dd0c414f476')
            ->assertJsonPath('data.key_id', '365CZB3ST7')
            ->assertJsonMissingPath('data.private_key');

        $this->assertDatabaseHas('apple_app_store_accounts', [
            'tenant_id' => $tenant->id,
            'issuer_id' => '3d24e14c-e344-4c39-bd2d-7dd0c414f476',
            'key_id' => '365CZB3ST7',
            'is_active' => true,
        ]);

        $stored = DB::table('apple_app_store_accounts')
            ->where('tenant_id', $tenant->id)
            ->where('issuer_id', '3d24e14c-e344-4c39-bd2d-7dd0c414f476')
            ->value('private_key');

        expect($stored)->not->toBe(APPLE_TEST_PRIVATE_KEY);

        $accountId = $response->json('data.id');
        $testResponse = $this->postJson("/api/v1/tenants/{$tenant->id}/apple-app-store/accounts/{$accountId}/test");

        $testResponse->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('reason', null);
    });

    it('prevents members from storing app store accounts', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/apple-app-store/accounts", [
            'name' => 'LocalClarity API key',
            'issuer_id' => '3d24e14c-e344-4c39-bd2d-7dd0c414f476',
            'key_id' => '365CZB3ST7',
            'private_key' => APPLE_TEST_PRIVATE_KEY,
        ]);

        $response->assertForbidden();
    });

    it('stores app mapping and lists accounts/apps for a tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();

        Sanctum::actingAs($user);

        $accountResponse = $this->postJson("/api/v1/tenants/{$tenant->id}/apple-app-store/accounts", [
            'name' => '[Expo] EAS Submit i_84lrrcJ7',
            'issuer_id' => '3d24e14c-e344-4c39-bd2d-7dd0c414f476',
            'key_id' => '365CZB3ST7',
            'private_key' => APPLE_TEST_PRIVATE_KEY,
        ]);

        $accountId = $accountResponse->json('data.id');

        $appResponse = $this->postJson("/api/v1/tenants/{$tenant->id}/apple-app-store/apps", [
            'apple_app_store_account_id' => $accountId,
            'name' => 'Clarity SEO iOS',
            'app_store_id' => '1234567890',
            'bundle_id' => 'com.localclarity.app',
            'country_code' => 'us',
        ]);

        $appResponse->assertCreated()
            ->assertJsonPath('data.apple_app_store_account_id', $accountId)
            ->assertJsonPath('data.name', 'Clarity SEO iOS')
            ->assertJsonPath('data.country_code', 'US');

        $this->assertDatabaseHas('apple_app_store_apps', [
            'tenant_id' => $tenant->id,
            'apple_app_store_account_id' => $accountId,
            'app_store_id' => '1234567890',
            'bundle_id' => 'com.localclarity.app',
        ]);

        $accountsResponse = $this->getJson("/api/v1/tenants/{$tenant->id}/apple-app-store/accounts");
        $appsResponse = $this->getJson("/api/v1/tenants/{$tenant->id}/apple-app-store/apps");

        $accountsResponse->assertOk()
            ->assertJsonPath('data.0.id', $accountId);

        $appsResponse->assertOk()
            ->assertJsonPath('data.0.app_store_id', '1234567890');
    });

    it('rejects app creation when account belongs to another tenant', function () {
        $adminA = User::factory()->create();
        $tenantA = Tenant::factory()->hasAttached($adminA, ['role' => 'admin'])->create();
        $adminB = User::factory()->create();
        $tenantB = Tenant::factory()->hasAttached($adminB, ['role' => 'admin'])->create();

        Sanctum::actingAs($adminA);

        $accountResponse = $this->postJson("/api/v1/tenants/{$tenantA->id}/apple-app-store/accounts", [
            'name' => 'Tenant A Key',
            'issuer_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'key_id' => 'A65CZB3ST7',
            'private_key' => APPLE_TEST_PRIVATE_KEY,
        ]);

        $accountId = $accountResponse->json('data.id');

        Sanctum::actingAs($adminB);

        $response = $this->postJson("/api/v1/tenants/{$tenantB->id}/apple-app-store/apps", [
            'apple_app_store_account_id' => $accountId,
            'name' => 'Wrong Tenant App',
            'app_store_id' => '9988776655',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'The selected App Store account does not belong to this tenant.');
    });

    it('can perform live api check with mocked app store response', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();

        Sanctum::actingAs($user);

        $accountResponse = $this->postJson("/api/v1/tenants/{$tenant->id}/apple-app-store/accounts", [
            'name' => 'LocalClarity API key',
            'issuer_id' => '3d24e14c-e344-4c39-bd2d-7dd0c414f476',
            'key_id' => '365CZB3ST7',
            'private_key' => APPLE_TEST_PRIVATE_KEY,
        ]);

        $accountId = $accountResponse->json('data.id');

        Http::fake([
            'https://api.appstoreconnect.apple.com/v1/apps*' => Http::response([
                'data' => [
                    ['id' => '1234567890', 'type' => 'apps'],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/apple-app-store/accounts/{$accountId}/test?live=1");

        $response->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('status_code', 200);
    });
});
