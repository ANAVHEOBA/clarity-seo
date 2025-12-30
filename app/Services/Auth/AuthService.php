<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DataTransferObjects\Auth\RegisterData;
use App\Exceptions\Auth\EmailNotVerifiedException;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthService
{
    public function register(RegisterData $data): User
    {
        $user = User::create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => Hash::make($data->password),
        ]);

        event(new Registered($user));

        return $user;
    }

    /**
     * @throws InvalidCredentialsException
     * @throws EmailNotVerifiedException
     */
    public function login(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException;
        }

        return $user;
    }

    public function createToken(User $user, string $name = 'auth-token'): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    public function revokeCurrentToken(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }
}
