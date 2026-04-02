<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\DataTransferObjects\Auth\RegisterData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Tenant\TenantService;
use App\Support\Portal\PortalContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegisterController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly TenantService $tenantService,
        private readonly PortalContext $portalContext,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $portalTenant = $this->portalContext->tenant();

        if ($portalTenant !== null && ! $portalTenant->public_signup_enabled) {
            return response()->json([
                'message' => 'Registration is disabled for this portal. Please contact support.',
                'support_email' => $portalTenant->support_email,
            ], Response::HTTP_FORBIDDEN);
        }

        $data = RegisterData::fromArray($request->validated());

        $user = $this->authService->register($data);

        if ($portalTenant !== null) {
            $this->tenantService->joinTenantAsMember($portalTenant, $user);
        }

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'data' => [
                'user' => new UserResource($user),
            ],
        ], Response::HTTP_CREATED);
    }
}
