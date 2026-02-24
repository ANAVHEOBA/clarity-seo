<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Listing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\StoreAppleAppStoreAccountRequest;
use App\Http\Requests\Listing\StoreAppleAppStoreAppRequest;
use App\Http\Resources\Listing\AppleAppStoreAccountResource;
use App\Http\Resources\Listing\AppleAppStoreAppResource;
use App\Models\AppleAppStoreAccount;
use App\Models\AppleAppStoreApp;
use App\Models\Tenant;
use App\Services\Listing\AppleAppStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AppleAppStoreController extends Controller
{
    public function __construct(
        private readonly AppleAppStoreService $appleService,
    ) {}

    public function accounts(Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        $accounts = $tenant->appStoreAccounts()->latest()->get();

        return AppleAppStoreAccountResource::collection($accounts);
    }

    public function storeAccount(StoreAppleAppStoreAccountRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $validated = $request->validated();

        $privateKey = trim((string) $validated['private_key']);
        $privateKey = str_replace(["\r\n", "\r", "\\n"], ["\n", "\n", "\n"], $privateKey);

        if (! openssl_pkey_get_private($privateKey)) {
            return response()->json([
                'message' => 'The private_key is not a valid PEM private key.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $account = $tenant->appStoreAccounts()->updateOrCreate(
            [
                'issuer_id' => $validated['issuer_id'],
                'key_id' => strtoupper($validated['key_id']),
            ],
            [
                'name' => $validated['name'],
                'private_key' => $privateKey,
                'is_active' => $validated['is_active'] ?? true,
                'metadata' => $validated['metadata'] ?? null,
            ]
        );

        return (new AppleAppStoreAccountResource($account))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroyAccount(Tenant $tenant, AppleAppStoreAccount $account): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($account->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Account not found.'], Response::HTTP_NOT_FOUND);
        }

        $account->update(['is_active' => false]);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function testAccount(Request $request, Tenant $tenant, AppleAppStoreAccount $account): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($account->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Account not found.'], Response::HTTP_NOT_FOUND);
        }

        $result = $this->appleService->validateAccountForApi($account);

        if (! $result['valid']) {
            return response()->json([
                'message' => 'Account test failed.',
                'valid' => false,
                'reason' => $result['reason'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (filter_var($request->input('live', false), FILTER_VALIDATE_BOOLEAN)) {
            $live = $this->appleService->pingAppsEndpoint($account);

            return response()->json([
                'message' => $live['message'],
                'valid' => $live['ok'],
                'reason' => $live['ok'] ? null : ($live['body']['errors'][0]['detail'] ?? null),
                'status_code' => $live['status'],
                'response' => $live['body'],
            ], $live['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Account test passed. JWT can be generated.',
            'valid' => true,
            'reason' => null,
            'jwt_preview' => substr((string) $result['jwt'], 0, 32).'...',
        ]);
    }

    public function apps(Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        $apps = $tenant->appStoreApps()->latest()->get();

        return AppleAppStoreAppResource::collection($apps);
    }

    public function storeApp(StoreAppleAppStoreAppRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $validated = $request->validated();

        if (! empty($validated['apple_app_store_account_id'])) {
            $account = AppleAppStoreAccount::query()->find($validated['apple_app_store_account_id']);

            if (! $account || $account->tenant_id !== $tenant->id) {
                return response()->json([
                    'message' => 'The selected App Store account does not belong to this tenant.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $where = ['tenant_id' => $tenant->id];
        if (! empty($validated['app_store_id'])) {
            $where['app_store_id'] = $validated['app_store_id'];
        } else {
            $where['bundle_id'] = $validated['bundle_id'];
        }

        $app = $tenant->appStoreApps()->updateOrCreate(
            $where,
            [
                'apple_app_store_account_id' => $validated['apple_app_store_account_id'] ?? null,
                'name' => $validated['name'],
                'app_store_id' => $validated['app_store_id'] ?? null,
                'bundle_id' => $validated['bundle_id'] ?? null,
                'country_code' => $validated['country_code'] ?? 'US',
                'is_active' => $validated['is_active'] ?? true,
                'metadata' => $validated['metadata'] ?? null,
            ]
        );

        return (new AppleAppStoreAppResource($app))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroyApp(Tenant $tenant, AppleAppStoreApp $app): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($app->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'App not found.'], Response::HTTP_NOT_FOUND);
        }

        $app->update(['is_active' => false]);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
