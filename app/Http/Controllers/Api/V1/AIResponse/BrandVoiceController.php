<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AIResponse;

use App\Http\Controllers\Controller;
use App\Http\Requests\AIResponse\StoreBrandVoiceRequest;
use App\Http\Requests\AIResponse\UpdateBrandVoiceRequest;
use App\Http\Resources\AIResponse\BrandVoiceResource;
use App\Models\Tenant;
use App\Services\AIResponse\BrandVoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BrandVoiceController extends Controller
{
    public function __construct(
        protected BrandVoiceService $brandVoiceService
    ) {}

    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorizeTenantAccess($tenant);

        $brandVoices = $this->brandVoiceService->listForTenant($tenant, $request->all());

        return BrandVoiceResource::collection($brandVoices);
    }

    public function store(StoreBrandVoiceRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeTenantAccess($tenant);

        $brandVoice = $this->brandVoiceService->create($tenant, $request->validated());

        return response()->json([
            'data' => new BrandVoiceResource($brandVoice),
        ], 201);
    }

    public function show(Request $request, Tenant $tenant, int $brandVoiceId): JsonResponse
    {
        $this->authorizeTenantAccess($tenant);

        $brandVoice = $this->brandVoiceService->findForTenant($tenant, $brandVoiceId);

        if (! $brandVoice) {
            return response()->json(['message' => 'Brand voice not found'], 404);
        }

        return response()->json([
            'data' => new BrandVoiceResource($brandVoice),
        ]);
    }

    public function update(
        UpdateBrandVoiceRequest $request,
        Tenant $tenant,
        int $brandVoiceId
    ): JsonResponse {
        $this->authorizeTenantAccess($tenant);

        $brandVoice = $this->brandVoiceService->findForTenant($tenant, $brandVoiceId);

        if (! $brandVoice) {
            return response()->json(['message' => 'Brand voice not found'], 404);
        }

        $brandVoice = $this->brandVoiceService->update($brandVoice, $request->validated());

        return response()->json([
            'data' => new BrandVoiceResource($brandVoice),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, int $brandVoiceId): JsonResponse
    {
        $this->authorizeTenantAccess($tenant);

        $brandVoice = $this->brandVoiceService->findForTenant($tenant, $brandVoiceId);

        if (! $brandVoice) {
            return response()->json(['message' => 'Brand voice not found'], 404);
        }

        $this->brandVoiceService->delete($brandVoice);

        return response()->json(null, 204);
    }

    protected function authorizeTenantAccess(Tenant $tenant): void
    {
        if (! $tenant->users()->where('user_id', auth()->id())->exists()) {
            abort(403, 'You do not have access to this tenant.');
        }
    }
}
