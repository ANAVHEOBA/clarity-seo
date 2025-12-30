<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

describe('Password Reset Request', function () {
    it('displays the forgot password page', function () {
        $response = $this->get('/forgot-password');

        $response->assertSuccessful();
        $response->assertSee('Forgot Password');
    });

    it('sends a password reset link to valid email', function () {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $response = $this->post('/forgot-password', [
            'email' => 'john@example.com',
        ]);

        $response->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    });

    it('does not reveal if email exists', function () {
        Notification::fake();

        $response = $this->post('/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertSessionHas('status');

        Notification::assertNothingSent();
    });

    it('requires a valid email format', function () {
        $response = $this->post('/forgot-password', [
            'email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('email');
    });

    it('rate limits password reset requests', function () {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $this->post('/forgot-password', ['email' => 'john@example.com']);

        $response = $this->post('/forgot-password', [
            'email' => 'john@example.com',
        ]);

        $response->assertSessionHasErrors('email');
    });
});

describe('Password Reset', function () {
    it('displays the password reset page', function () {
        $user = User::factory()->create();

        $token = Password::createToken($user);

        $response = $this->get("/reset-password/{$token}?email={$user->email}");

        $response->assertSuccessful();
        $response->assertSee('Reset Password');
    });

    it('allows users to reset their password with valid token', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'john@example.com',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHas('status');

        expect(Hash::check('NewSecurePassword123!', $user->fresh()->password))->toBeTrue();
    });

    it('fails with invalid token', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $response = $this->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => 'john@example.com',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response->assertSessionHasErrors('email');
    });

    it('fails with expired token', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $token = Password::createToken($user);

        $this->travel(2)->hours();

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'john@example.com',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response->assertSessionHasErrors('email');
    });

    it('fails with mismatched email', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'wrong@example.com',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response->assertSessionHasErrors('email');
    });

    it('requires password confirmation', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'john@example.com',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'DifferentPassword!',
        ]);

        $response->assertSessionHasErrors('password');
    });

    it('invalidates token after successful reset', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'john@example.com',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'john@example.com',
            'password' => 'AnotherPassword123!',
            'password_confirmation' => 'AnotherPassword123!',
        ]);

        $response->assertSessionHasErrors('email');
    });
});

describe('API Password Reset', function () {
    it('sends password reset link via API', function () {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'john@example.com',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Password reset link sent',
        ]);

        Notification::assertSentTo($user, ResetPassword::class);
    });

    it('resets password via API', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'john@example.com',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Password reset successfully',
        ]);

        expect(Hash::check('NewSecurePassword123!', $user->fresh()->password))->toBeTrue();
    });
});
