<?php

declare(strict_types=1);

use App\Models\User;

describe('Login API', function () {
    it('allows verified users to login with valid credentials', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'message',
            'data' => [
                'user' => ['id', 'name', 'email'],
                'token',
            ],
        ]);
    });

    it('returns a valid API token on login', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $token = $response->json('data.token');

        expect($token)->not->toBeNull();
        expect($user->tokens()->count())->toBe(1);
    });

    it('returns user data on successful login', function () {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertJson([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => 'John Doe',
                    'email' => 'john@example.com',
                ],
            ],
        ]);
    });
});

describe('Login with Unverified Email', function () {
    it('rejects login for unverified users', function () {
        User::factory()->unverified()->create([
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertForbidden();
        $response->assertJson([
            'message' => 'Please verify your email address before logging in.',
        ]);
    });
});

describe('Login with Invalid Credentials', function () {
    it('fails with non-existent email', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertUnauthorized();
        $response->assertJson([
            'message' => 'Invalid credentials.',
        ]);
    });

    it('fails with incorrect password', function () {
        User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
            'password' => 'WrongPassword!',
        ]);

        $response->assertUnauthorized();
        $response->assertJson([
            'message' => 'Invalid credentials.',
        ]);
    });

    it('does not reveal if email exists', function () {
        User::factory()->create([
            'email' => 'exists@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $responseNonExistent = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'WrongPassword!',
        ]);

        $responseWrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'exists@example.com',
            'password' => 'WrongPassword!',
        ]);

        expect($responseNonExistent->json('message'))
            ->toBe($responseWrongPassword->json('message'));
    });
});

describe('Login Validation', function () {
    it('requires an email', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    });

    it('requires a valid email format', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    });

    it('requires a password', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
            'password' => '',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('password');
    });

    it('returns all validation errors at once', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '',
            'password' => '',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email', 'password']);
    });
});
