<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
        );

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
