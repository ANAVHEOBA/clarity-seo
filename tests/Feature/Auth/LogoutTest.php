<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('Logout', function () {
    it('allows authenticated users to logout', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    });

    it('invalidates the session on logout', function () {
        $user = User::factory()->create();

        $this->actingAs($user);

        $sessionId = session()->getId();

        $this->post('/logout');

        expect(session()->getId())->not->toBe($sessionId);
    });

    it('redirects guests attempting to logout', function () {
        $response = $this->post('/logout');

        $response->assertRedirect('/login');
    });
});

describe('Logout from All Devices', function () {
    it('allows users to logout from all devices', function () {
        $user = User::factory()->create([
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->actingAs($user)->post('/logout-all-devices', [
            'password' => 'SecurePassword123!',
        ]);

        $response->assertRedirect('/');
        $this->assertGuest();
    });

    it('requires password confirmation to logout from all devices', function () {
        $user = User::factory()->create([
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->actingAs($user)->post('/logout-all-devices', [
            'password' => 'WrongPassword!',
        ]);

        $response->assertSessionHasErrors('password');
    });
});

describe('API Logout', function () {
    it('allows authenticated users to logout via API', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Successfully logged out',
        ]);
    });

    it('revokes the current token on logout', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertSuccessful();

        expect($user->tokens()->count())->toBe(0);
    });

    it('allows users to logout from all devices via API', function () {
        $user = User::factory()->create();

        $user->createToken('token-1');
        $user->createToken('token-2');
        $user->createToken('token-3');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/logout-all');

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Successfully logged out from all devices',
        ]);

        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    it('returns unauthorized for unauthenticated logout requests', function () {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertUnauthorized();
    });
});
