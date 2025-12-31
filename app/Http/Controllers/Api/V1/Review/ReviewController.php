<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Review;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewResponseRequest;
use App\Http\Requests\Review\UpdateReviewResponseRequest;
use App\Http\Resources\Review\ReviewResource;
use App\Http\Resources\Review\ReviewResponseResource;
use App\Http\Resources\Review\ReviewStatsResource;
use App\Models\Location;
use App\Models\Review;
use App\Models\Tenant;
use App\Services\Review\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    public function __construct(
        protected ReviewService $reviewService
    ) {}

    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        $reviews = $this->reviewService->listForTenant($tenant, $request->all());

        return ReviewResource::collection($reviews);
    }

    public function locationIndex(Request $request, Tenant $tenant, Location $location): AnonymousResourceCollection
    {
        $this->authorize('view', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            abort(404);
        }

        $reviews = $this->reviewService->listForLocation($location, $request->all());

        return ReviewResource::collection($reviews);
    }

    public function show(Tenant $tenant, Review $review): ReviewResource
    {
        $this->authorize('view', $tenant);

        $review = $this->reviewService->findForTenant($tenant, $review->id);

        if (! $review) {
            abort(404);
        }

        return new ReviewResource($review);
    }

    public function stats(Tenant $tenant): ReviewStatsResource
    {
        $this->authorize('view', $tenant);

        $stats = $this->reviewService->getStats($tenant);

        return new ReviewStatsResource($stats);
    }

    public function locationStats(Tenant $tenant, Location $location): ReviewStatsResource
    {
        $this->authorize('view', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            abort(404);
        }

        $stats = $this->reviewService->getStats($tenant, $location);

        return new ReviewStatsResource($stats);
    }

    public function storeResponse(StoreReviewResponseRequest $request, Tenant $tenant, Review $review): JsonResponse
    {
        $this->authorize('update', $tenant);

        $review = $this->reviewService->findForTenant($tenant, $review->id);

        if (! $review) {
            abort(404);
        }

        if ($review->response) {
            return response()->json([
                'message' => 'Review already has a response.',
                'errors' => ['review' => ['This review already has a response.']],
            ], 422);
        }

        $response = $this->reviewService->createResponse(
            $review,
            $request->user(),
            $request->validated()
        );

        return (new ReviewResponseResource($response))
            ->response()
            ->setStatusCode(201);
    }

    public function updateResponse(UpdateReviewResponseRequest $request, Tenant $tenant, Review $review): ReviewResponseResource|JsonResponse
    {
        $this->authorize('update', $tenant);

        $review = $this->reviewService->findForTenant($tenant, $review->id);

        if (! $review || ! $review->response) {
            abort(404);
        }

        if ($review->response->isPublished()) {
            return response()->json([
                'message' => 'Cannot update a published response.',
            ], 403);
        }

        $response = $this->reviewService->updateResponse(
            $review->response,
            $request->validated()
        );

        return new ReviewResponseResource($response);
    }

    public function publishResponse(Tenant $tenant, Review $review): ReviewResponseResource|JsonResponse
    {
        $this->authorize('update', $tenant);

        $review = $this->reviewService->findForTenant($tenant, $review->id);

        if (! $review || ! $review->response) {
            abort(404);
        }

        // AI-generated responses require approval before publishing
        if ($review->response->ai_generated && ! $review->response->isApproved()) {
            return response()->json([
                'message' => 'Response must be approved before publishing',
            ], 422);
        }

        $response = $this->reviewService->publishResponse($review->response);

        return new ReviewResponseResource($response);
    }

    public function destroyResponse(Tenant $tenant, Review $review): JsonResponse
    {
        $this->authorize('update', $tenant);

        $review = $this->reviewService->findForTenant($tenant, $review->id);

        if (! $review || ! $review->response) {
            abort(404);
        }

        $this->reviewService->deleteResponse($review->response);

        return response()->json(null, 204);
    }

    public function sync(Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            abort(404);
        }

        if (! $location->hasGooglePlaceId() && ! $location->hasYelpBusinessId()) {
            return response()->json([
                'message' => 'Location must have a Google Place ID or Yelp Business ID to sync reviews.',
                'errors' => ['google_place_id' => ['A Google Place ID or Yelp Business ID is required.']],
            ], 422);
        }

        $this->reviewService->syncReviewsForLocation($location);

        return response()->json([
            'message' => 'Review sync initiated.',
        ], 202);
    }
}
