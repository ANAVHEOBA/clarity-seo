<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AIResponse;

use App\Http\Controllers\Controller;
use App\Http\Requests\AIResponse\BulkGenerateAIResponseRequest;
use App\Http\Requests\AIResponse\GenerateAIResponseRequest;
use App\Http\Resources\AIResponse\AIResponseHistoryResource;
use App\Http\Resources\AIResponse\AIResponseResource;
use App\Models\Location;
use App\Models\Review;
use App\Models\Tenant;
use App\Services\AIResponse\AIResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AIResponseController extends Controller
{
    public function __construct(
        protected AIResponseService $aiResponseService
    ) {}

    public function generate(
        GenerateAIResponseRequest $request,
        Tenant $tenant,
        Review $review
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);
        $this->authorizeReviewAccess($tenant, $review);

        try {
            $result = $this->aiResponseService->generateResponse(
                $review,
                $request->user(),
                $request->validated()
            );

            if (! $result) {
                return response()->json([
                    'message' => 'Failed to generate AI response',
                ], 503);
            }

            $responseData = (new AIResponseResource($result['response']))->toArray(request());

            if (isset($result['quality_score'])) {
                $responseData['quality_score'] = $result['quality_score'];
                $responseData['quality_feedback'] = $result['quality_feedback'];
            }

            return response()->json(['data' => $responseData], 201);
        } catch (\RuntimeException $e) {
            $status = match (true) {
                str_contains($e->getMessage(), 'rate limit') => 429,
                str_contains($e->getMessage(), 'not configured') => 503,
                default => 503,
            };

            return response()->json(['message' => $e->getMessage()], $status);
        }
    }

    public function regenerate(
        GenerateAIResponseRequest $request,
        Tenant $tenant,
        Review $review
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);
        $this->authorizeReviewAccess($tenant, $review);

        try {
            $result = $this->aiResponseService->regenerateResponse(
                $review,
                $request->user(),
                $request->validated()
            );

            if (! $result) {
                return response()->json([
                    'message' => 'Failed to regenerate AI response',
                ], 503);
            }

            return response()->json([
                'data' => new AIResponseResource($result['response']),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function bulkGenerate(
        BulkGenerateAIResponseRequest $request,
        Tenant $tenant
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);

        // Validate all reviews belong to tenant
        $locationIds = $tenant->locations()->pluck('id');
        $validReviewIds = Review::whereIn('location_id', $locationIds)
            ->whereIn('id', $request->review_ids)
            ->pluck('id')
            ->toArray();

        try {
            $result = $this->aiResponseService->generateBulkResponses(
                $validReviewIds,
                $request->user(),
                $request->validated()
            );

            return response()->json(['data' => $result]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function bulkGenerateForLocation(
        GenerateAIResponseRequest $request,
        Tenant $tenant,
        Location $location
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);
        $this->authorizeLocationAccess($tenant, $location);

        try {
            $result = $this->aiResponseService->generateLocationResponses(
                $location,
                $request->user(),
                $request->validated()
            );

            return response()->json(['data' => $result]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function history(
        Request $request,
        Tenant $tenant,
        Review $review
    ): AnonymousResourceCollection {
        $this->authorizeTenantAccess($tenant);
        $this->authorizeReviewAccess($tenant, $review);

        $history = $this->aiResponseService->getResponseHistory($review);

        return AIResponseHistoryResource::collection($history);
    }

    public function approve(
        Request $request,
        Tenant $tenant,
        Review $review
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);
        $this->authorizeReviewAccess($tenant, $review);

        $response = $review->response;

        if (! $response) {
            return response()->json(['message' => 'No response found'], 404);
        }

        $response->approve($request->user());

        return response()->json([
            'data' => new AIResponseResource($response->fresh()),
        ]);
    }

    public function reject(
        Request $request,
        Tenant $tenant,
        Review $review
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);
        $this->authorizeReviewAccess($tenant, $review);

        $request->validate([
            'reason' => ['sometimes', 'string', 'max:1000'],
        ]);

        $response = $review->response;

        if (! $response) {
            return response()->json(['message' => 'No response found'], 404);
        }

        $response->reject($request->input('reason', 'No reason provided'));

        return response()->json([
            'data' => new AIResponseResource($response->fresh()),
        ]);
    }

    public function publish(
        Request $request,
        Tenant $tenant,
        Review $review
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);
        $this->authorizeReviewAccess($tenant, $review);

        $response = $review->response;

        if (! $response) {
            return response()->json(['message' => 'No response found'], 404);
        }

        if (! $response->isApproved()) {
            return response()->json([
                'message' => 'Response must be approved before publishing',
            ], 422);
        }

        $response->publish();

        return response()->json([
            'data' => new AIResponseResource($response->fresh()),
        ]);
    }

    public function suggestions(
        Request $request,
        Tenant $tenant,
        Review $review
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);
        $this->authorizeReviewAccess($tenant, $review);

        $response = $review->response;

        if (! $response) {
            return response()->json(['message' => 'No response found'], 404);
        }

        $suggestions = $this->aiResponseService->getSuggestions($response);

        if (! $suggestions) {
            return response()->json([
                'message' => 'Failed to generate suggestions',
            ], 503);
        }

        return response()->json(['data' => $suggestions]);
    }

    public function stats(
        Request $request,
        Tenant $tenant
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);

        $stats = $this->aiResponseService->getStats($tenant);

        return response()->json(['data' => $stats]);
    }

    public function locationStats(
        Request $request,
        Tenant $tenant,
        Location $location
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);
        $this->authorizeLocationAccess($tenant, $location);

        $stats = $this->aiResponseService->getStats($tenant, $location);

        return response()->json(['data' => $stats]);
    }

    public function usage(
        Request $request,
        Tenant $tenant
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);

        $usage = $this->aiResponseService->getUsageOverTime($tenant, [
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ]);

        return response()->json(['data' => $usage]);
    }

    protected function authorizeTenantAccess(Tenant $tenant): void
    {
        if (! $tenant->users()->where('user_id', auth()->id())->exists()) {
            abort(403, 'You do not have access to this tenant.');
        }
    }

    protected function authorizeReviewAccess(Tenant $tenant, Review $review): void
    {
        $locationIds = $tenant->locations()->pluck('id');

        if (! $locationIds->contains($review->location_id)) {
            abort(404, 'Review not found.');
        }
    }

    protected function authorizeLocationAccess(Tenant $tenant, Location $location): void
    {
        if ($location->tenant_id !== $tenant->id) {
            abort(404, 'Location not found.');
        }
    }
}
