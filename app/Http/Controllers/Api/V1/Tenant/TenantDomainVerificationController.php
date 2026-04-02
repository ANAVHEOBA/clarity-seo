<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenant\DomainVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TenantDomainVerificationController extends Controller
{
    public function __construct(
        private readonly DomainVerificationService $domainVerificationService,
    ) {}

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        try {
            return response()->json([
                'data' => $this->domainVerificationService->status($tenant, $this->requestContext($request)),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function requestChallenge(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        try {
            return response()->json([
                'message' => 'Domain verification challenge created successfully.',
                'data' => $this->domainVerificationService->issueChallenge($tenant, $this->requestContext($request)),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function verify(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $validated = $request->validate([
            'verification_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'verification_port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'verification_scheme' => ['sometimes', 'nullable', 'in:http,https'],
        ]);

        try {
            $result = $this->domainVerificationService->verify($tenant, array_merge(
                $this->requestContext($request),
                $validated
            ));

            return response()->json($result, $result['verified'] ? 200 : 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $tenant = $this->domainVerificationService->clear($tenant);

        return response()->json([
            'message' => 'Domain verification state cleared.',
            'data' => [
                'custom_domain' => $tenant->custom_domain,
                'custom_domain_verified_at' => $tenant->custom_domain_verified_at,
                'domain_verification_requested_at' => $tenant->domain_verification_requested_at,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(Request $request): array
    {
        return [
            'request_host' => $request->getHost(),
            'request_port' => $request->getPort(),
            'request_scheme' => $request->getScheme(),
        ];
    }
}
