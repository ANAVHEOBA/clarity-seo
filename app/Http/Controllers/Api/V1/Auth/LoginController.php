<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\AuthService;
use App\Support\Portal\PortalContext;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly PortalContext $portalContext,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
        );

        $portalTenant = $this->portalContext->tenant();

        if ($portalTenant !== null) {
            if (! $user->belongsToTenant($portalTenant)) {
                return response()->json([
                    'message' => 'This account does not have access to this portal.',
                ], 403);
            }

            $user->switchTenant($portalTenant);
            $user->refresh();
        }

        $token = $this->authService->createToken($user);

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
        ]);
    }
}
