<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

describe('Registration API', function () {
    it('allows users to register with valid data', function () {
        Event::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'message',
            'data' => [
                'user' => ['id', 'name', 'email'],
            ],
        ]);

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        Event::assertDispatched(Registered::class);
    });

    it('hashes the password when registering', function () {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $user = User::where('email', 'john@example.com')->first();

        expect(Hash::check('SecurePassword123!', $user->password))->toBeTrue();
        expect($user->password)->not->toBe('SecurePassword123!');
    });

    it('creates user with unverified email', function () {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $user = User::where('email', 'john@example.com')->first();

        expect($user->email_verified_at)->toBeNull();
        expect($user->hasVerifiedEmail())->toBeFalse();
    });

    it('returns proper response message', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertJson([
            'message' => 'Registration successful. Please check your email to verify your account.',
        ]);
    });
});

describe('Registration Validation', function () {
    it('requires a name', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('name');
    });

    it('requires an email', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => '',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    });

    it('requires a valid email format', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'not-an-email',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    });

    it('requires a unique email', function () {
        User::factory()->create(['email' => 'john@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    });

    it('requires a password', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => '',
            'password_confirmation' => '',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('password');
    });

    it('requires password confirmation', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'DifferentPassword!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('password');
    });

    it('requires minimum password length', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('password');
    });

    it('returns all validation errors at once', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'invalid',
            'password' => 'short',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name', 'email', 'password']);
    });
});
