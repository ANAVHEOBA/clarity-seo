<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Models\AppleAppStoreAccount;
use App\Models\AppleAppStoreApp;
use App\Models\Listing;
use App\Models\Location;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AppleAppStoreListingService
{
    public function __construct(
        private readonly AppleAppStoreService $appleService,
    ) {}

    public function syncListing(Location $location): ?Listing
    {
        if (! $location->hasAppleAppStoreAppId()) {
            return null;
        }

        $appStoreAppId = (string) $location->apple_app_store_app_id;
        $account = $this->resolveAccountForLocation($location, $appStoreAppId);

        if (! $account || ! $account->is_active) {
            return null;
        }

        $tokenResult = $this->appleService->validateAccountForApi($account);
        if (! $tokenResult['valid'] || empty($tokenResult['jwt'])) {
            Log::error('Apple listing sync token validation failed', [
                'location_id' => $location->id,
                'reason' => $tokenResult['reason'],
            ]);
            return null;
        }

        $baseUrl = $this->appleService->getApiBaseUrl();
        $response = Http::withToken((string) $tokenResult['jwt'])
            ->acceptJson()
            ->get("{$baseUrl}/v1/apps/{$appStoreAppId}", [
                'include' => 'appInfos',
            ]);

        if (! $response->successful()) {
            Log::error('Apple listing sync failed', [
                'location_id' => $location->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            return null;
        }

        $payload = $response->json();
        $app = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $attrs = is_array($app['attributes'] ?? null) ? $app['attributes'] : [];

        $mappedApp = AppleAppStoreApp::query()
            ->where('tenant_id', $location->tenant_id)
            ->where('app_store_id', $appStoreAppId)
            ->first();

        $listing = Listing::updateOrCreate(
            [
                'location_id' => $location->id,
                'platform' => Listing::PLATFORM_APPLE_APP_STORE,
            ],
            [
                'external_id' => (string) ($app['id'] ?? $appStoreAppId),
                'status' => Listing::STATUS_SYNCED,
                'name' => $attrs['name'] ?? $mappedApp?->name ?? $location->name,
                'country' => $mappedApp?->country_code ?? $location->country,
                'description' => $attrs['subtitle'] ?? null,
                'categories' => ['ios_app_store'],
                'attributes' => [
                    'bundle_id' => $attrs['bundleId'] ?? $mappedApp?->bundle_id,
                    'sku' => $attrs['sku'] ?? null,
                    'primary_locale' => $attrs['primaryLocale'] ?? null,
                    'is_or_ever_was_made_for_kids' => $attrs['isOrEverWasMadeForKids'] ?? null,
                    'content_rights_declaration' => $attrs['contentRightsDeclaration'] ?? null,
                    'streamlined_purchasing_enabled' => $attrs['streamlinedPurchasingEnabled'] ?? null,
                ],
                'last_synced_at' => now(),
                'error_message' => null,
            ]
        );

        return $listing;
    }

    public function publishListing(Location $location): bool
    {
        $listing = $this->syncListing($location);

        if (! $listing) {
            return false;
        }

        $listing->update([
            'last_published_at' => now(),
            'status' => Listing::STATUS_SYNCED,
            'error_message' => null,
        ]);

        return true;
    }

    private function resolveAccountForLocation(Location $location, string $appStoreAppId): ?AppleAppStoreAccount
    {
        $mappedApp = AppleAppStoreApp::query()
            ->where('tenant_id', $location->tenant_id)
            ->where('app_store_id', $appStoreAppId)
            ->where('is_active', true)
            ->first();

        if ($mappedApp && $mappedApp->apple_app_store_account_id) {
            $mappedAccount = AppleAppStoreAccount::query()
                ->where('id', $mappedApp->apple_app_store_account_id)
                ->where('tenant_id', $location->tenant_id)
                ->where('is_active', true)
                ->first();

            if ($mappedAccount) {
                return $mappedAccount;
            }
        }

        return AppleAppStoreAccount::query()
            ->where('tenant_id', $location->tenant_id)
            ->where('is_active', true)
            ->first();
    }
}
