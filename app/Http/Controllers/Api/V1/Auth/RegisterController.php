<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\DataTransferObjects\Auth\RegisterData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegisterController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $data = RegisterData::fromArray($request->validated());

        $user = $this->authService->register($data);

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'data' => [
                'user' => new UserResource($user),
            ],
        ], Response::HTTP_CREATED);
    }
}
