<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Listing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\StorePlatformCredentialRequest;
use App\Http\Resources\Listing\ListingResource;
use App\Http\Resources\Listing\ListingStatsResource;
use App\Http\Resources\Listing\PlatformCredentialResource;
use App\Models\Location;
use App\Models\Tenant;
use App\Services\Listing\FacebookService;
use App\Services\Listing\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ListingController extends Controller
{
    public function __construct(
        private readonly ListingService $listingService,
        private readonly FacebookService $facebookService,
    ) {}

    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        $listings = $this->listingService->listForTenant($tenant, $request->all());

        return ListingResource::collection($listings);
    }

    public function show(Tenant $tenant, int $listingId): ListingResource|JsonResponse
    {
        $this->authorize('view', $tenant);

        $listing = $this->listingService->find($listingId);

        if (! $listing || $listing->location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Listing not found.'], Response::HTTP_NOT_FOUND);
        }

        return new ListingResource($listing);
    }

    public function stats(Tenant $tenant): ListingStatsResource
    {
        $this->authorize('view', $tenant);

        $stats = $this->listingService->getStats($tenant);

        return new ListingStatsResource($stats);
    }

    public function locationStats(Tenant $tenant, Location $location): ListingStatsResource|JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found.'], Response::HTTP_NOT_FOUND);
        }

        $stats = $this->listingService->getStats($tenant, $location);

        return new ListingStatsResource($stats);
    }

    public function sync(Tenant $tenant, Location $location, string $platform): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found.'], Response::HTTP_NOT_FOUND);
        }

        $listing = $this->listingService->syncFromPlatform($location, $platform);

        if (! $listing) {
            return response()->json([
                'message' => 'Failed to sync listing. Please check platform credentials.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return (new ListingResource($listing))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function syncAll(Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found.'], Response::HTTP_NOT_FOUND);
        }

        $results = $this->listingService->syncAllPlatforms($location);

        $synced = collect($results)->filter()->count();

        return response()->json([
            'message' => "Synced {$synced} platform(s).",
            'data' => [
                'synced_count' => $synced,
                'platforms' => array_keys(array_filter($results, fn ($r) => $r !== null)),
            ],
        ]);
    }

    public function publish(Tenant $tenant, Location $location, string $platform): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found.'], Response::HTTP_NOT_FOUND);
        }

        $success = $this->listingService->publishToPlatform($location, $platform);

        if (! $success) {
            return response()->json([
                'message' => 'Failed to publish listing. Please check platform credentials.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Listing published successfully.',
        ]);
    }

    public function publishAll(Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found.'], Response::HTTP_NOT_FOUND);
        }

        $results = $this->listingService->publishToAllPlatforms($location);

        $published = collect($results)->filter()->count();

        return response()->json([
            'message' => "Published to {$published} platform(s).",
            'data' => [
                'published_count' => $published,
                'platforms' => array_keys(array_filter($results)),
            ],
        ]);
    }

    public function platforms(Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $platforms = $this->listingService->getAvailablePlatforms($tenant);

        return response()->json([
            'data' => $platforms,
        ]);
    }

    public function storeCredential(StorePlatformCredentialRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $validated = $request->validated();

        if ($validated['platform'] === 'facebook') {
            $credential = $this->facebookService->storeCredentials(
                $tenant,
                $validated['access_token'],
                $validated['page_id'],
                $validated['page_access_token'] ?? null,
                $validated['scopes'] ?? null
            );

            return (new PlatformCredentialResource($credential))
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);
        }

        if ($validated['platform'] === 'youtube') {
            // Update or create credential for YouTube
            $credential = $tenant->credentials()->updateOrCreate(
                ['platform' => 'youtube'],
                [
                    'external_id' => $validated['page_id'], // Storing Channel ID here
                    'access_token' => $validated['access_token'],
                    'refresh_token' => $validated['refresh_token'] ?? null,
                    'expires_at' => isset($validated['expires_at']) ? \Carbon\Carbon::parse($validated['expires_at']) : now()->addHour(),
                    'scopes' => $validated['scopes'] ?? ['https://www.googleapis.com/auth/youtube.force-ssl'],
                    'is_active' => true,
                    'metadata' => [
                        'channel_id' => $validated['page_id'],
                    ]
                ]
            );

            return (new PlatformCredentialResource($credential))
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);
        }

        return response()->json([
            'message' => 'Platform not supported yet.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function destroyCredential(Tenant $tenant, string $platform): JsonResponse
    {
        $this->authorize('update', $tenant);

        $credential = $this->facebookService->getCredentials($tenant);

        if (! $credential || $credential->platform !== $platform) {
            return response()->json(['message' => 'Credential not found.'], Response::HTTP_NOT_FOUND);
        }

        $credential->update(['is_active' => false]);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
