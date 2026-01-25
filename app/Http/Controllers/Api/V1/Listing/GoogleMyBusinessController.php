<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Listing;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Listing\GoogleMyBusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GoogleMyBusinessController extends Controller
{
    public function __construct(
        private readonly GoogleMyBusinessService $googleService,
    ) {
    }

    /**
     * Get the Google OAuth Login URL.
     */
    public function connect(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        // State can be used to pass the tenant ID or other context safely
        $state = base64_encode(json_encode(['tenant_id' => $tenant->id]));

        // This should match the callback route in your API
        $redirectUri = config('google.my_business.redirect_uri');

        $url = $this->googleService->getLoginUrl($redirectUri, $state);

        return response()->json([
            'url' => $url,
        ]);
    }

    /**
     * Handle the OAuth callback.
     */
    public function callback(Request $request): JsonResponse
    {
        $code = $request->input('code');
        $state = $request->input('state');
        $error = $request->input('error');

        if ($error) {
            return response()->json([
                'message' => 'Authorization failed.',
                'error' => $error,
                'error_description' => $request->input('error_description'),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$code) {
            return response()->json(['message' => 'Authorization code missing.'], Response::HTTP_BAD_REQUEST);
        }

        // Decode state to get tenant context
        $stateData = json_decode(base64_decode($state), true);
        $tenantId = $stateData['tenant_id'] ?? null;

        if (!$tenantId) {
            return response()->json(['message' => 'Invalid state.'], Response::HTTP_BAD_REQUEST);
        }

        // Exchange code for tokens
        $redirectUri = config('google.my_business.redirect_uri');
        $tokenData = $this->googleService->getAccessTokenFromCode($code, $redirectUri);

        if (!$tokenData) {
            return response()->json(['message' => 'Failed to get access token.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Fetch accounts to show to the user
        $accounts = $this->googleService->getAccounts($tokenData['access_token']);

        if (!$accounts) {
            return response()->json(['message' => 'Failed to fetch accounts.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Fetch locations for each account
        $accountsWithLocations = [];
        foreach ($accounts as $account) {
            $locations = $this->googleService->getLocations($account['name'], $tokenData['access_token']);
            $accountsWithLocations[] = [
                'account' => $account,
                'locations' => $locations ?? [],
            ];
        }

        return response()->json([
            'message' => 'Connected successfully. Please select a location.',
            'token_data' => [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_in' => $tokenData['expires_in'] ?? null,
            ],
            'accounts' => $accountsWithLocations,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Store Google My Business credentials.
     */
    public function storeCredential(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $validated = $request->validate([
            'access_token' => 'required|string',
            'refresh_token' => 'nullable|string',
            'expires_in' => 'nullable|integer',
            'location_name' => 'required|string', // Format: locations/{id}
        ]);

        $tokenData = [
            'access_token' => $validated['access_token'],
            'refresh_token' => $validated['refresh_token'] ?? null,
            'expires_in' => $validated['expires_in'] ?? 3600,
            'token_type' => 'Bearer',
        ];

        $credential = $this->googleService->storeCredentials(
            $tenant,
            $tokenData,
            $validated['location_name']
        );

        return response()->json([
            'message' => 'Google My Business credentials stored successfully.',
            'data' => [
                'id' => $credential->id,
                'platform' => $credential->platform,
                'location_name' => $credential->metadata['location_name'],
                'is_active' => $credential->is_active,
                'expires_at' => $credential->expires_at?->toISOString(),
            ],
        ], Response::HTTP_CREATED);
    }
}
