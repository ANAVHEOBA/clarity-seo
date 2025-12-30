<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('API Token Creation', function () {
    it('displays the API tokens page', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/api-tokens');

        $response->assertSuccessful();
        $response->assertSee('API Tokens');
    });

    it('allows users to create API tokens', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/api-tokens', [
            'name' => 'My API Token',
            'abilities' => ['read', 'write'],
        ]);

        $response->assertRedirect('/api-tokens');
        $response->assertSessionHas('token');

        expect($user->tokens()->count())->toBe(1);
        expect($user->tokens()->first()->name)->toBe('My API Token');
    });

    it('requires a token name', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/api-tokens', [
            'name' => '',
            'abilities' => ['read'],
        ]);

        $response->assertSessionHasErrors('name');
    });

    it('validates token abilities', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/api-tokens', [
            'name' => 'My Token',
            'abilities' => ['invalid-ability'],
        ]);

        $response->assertSessionHasErrors('abilities');
    });

    it('creates tokens with specific abilities', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api-tokens', [
            'name' => 'Read Only Token',
            'abilities' => ['read'],
        ]);

        $token = $user->tokens()->first();
        expect($token->abilities)->toBe(['read']);
    });
});

describe('API Token Listing', function () {
    it('lists all user tokens', function () {
        $user = User::factory()->create();

        $user->createToken('Token 1', ['read']);
        $user->createToken('Token 2', ['read', 'write']);
        $user->createToken('Token 3', ['*']);

        $response = $this->actingAs($user)->get('/api-tokens');

        $response->assertSee('Token 1');
        $response->assertSee('Token 2');
        $response->assertSee('Token 3');
    });

    it('does not show other users tokens', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1->createToken('User 1 Token');
        $user2->createToken('User 2 Token');

        $response = $this->actingAs($user1)->get('/api-tokens');

        $response->assertSee('User 1 Token');
        $response->assertDontSee('User 2 Token');
    });
});

describe('API Token Deletion', function () {
    it('allows users to delete their tokens', function () {
        $user = User::factory()->create();
        $token = $user->createToken('My Token');

        $response = $this->actingAs($user)->delete("/api-tokens/{$token->accessToken->id}");

        $response->assertRedirect('/api-tokens');

        expect($user->tokens()->count())->toBe(0);
    });

    it('cannot delete other users tokens', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token = $user1->createToken('User 1 Token');

        $response = $this->actingAs($user2)->delete("/api-tokens/{$token->accessToken->id}");

        $response->assertForbidden();

        expect($user1->tokens()->count())->toBe(1);
    });
});

describe('API Token Usage', function () {
    it('authenticates requests with valid token', function () {
        $user = User::factory()->create();
        $token = $user->createToken('My Token', ['*']);

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth/user');

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ]);
    });

    it('rejects requests with invalid token', function () {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/api/v1/auth/user');

        $response->assertUnauthorized();
    });

    it('rejects requests with revoked token', function () {
        $user = User::factory()->create();
        $token = $user->createToken('My Token');

        $user->tokens()->delete();

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth/user');

        $response->assertUnauthorized();
    });

    it('respects token abilities', function () {
        $user = User::factory()->create();
        $readOnlyToken = $user->createToken('Read Only', ['read']);

        Sanctum::actingAs($user, ['read']);

        $response = $this->postJson('/api/v1/locations', [
            'name' => 'Test Location',
        ]);

        $response->assertForbidden();
    });

    it('allows requests matching token abilities', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['read', 'write']);

        $response = $this->getJson('/api/v1/auth/user');

        $response->assertSuccessful();
    });

    it('records last used timestamp', function () {
        $user = User::factory()->create();
        $token = $user->createToken('My Token');

        expect($token->accessToken->last_used_at)->toBeNull();

        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth/user');

        expect($token->accessToken->fresh()->last_used_at)->not->toBeNull();
    });
});

describe('API Token Expiration', function () {
    it('creates tokens with expiration', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/api-tokens', [
            'name' => 'Expiring Token',
            'abilities' => ['read'],
            'expires_at' => now()->addDays(30)->toDateString(),
        ]);

        $token = $user->tokens()->first();
        expect($token->expires_at)->not->toBeNull();
    });

    it('rejects expired tokens', function () {
        $user = User::factory()->create();
        $token = $user->createToken('Expired Token');

        $token->accessToken->update([
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/auth/user');

        $response->assertUnauthorized();
    });
});

describe('API Token via JSON', function () {
    it('creates tokens via API', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/tokens', [
            'name' => 'New API Token',
            'abilities' => ['read', 'write'],
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => [
                'token',
                'name',
                'abilities',
            ],
        ]);
    });

    it('lists tokens via API', function () {
        $user = User::factory()->create();
        $user->createToken('Token 1');
        $user->createToken('Token 2');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/auth/tokens');

        $response->assertSuccessful();
        $response->assertJsonCount(2, 'data');
    });

    it('deletes tokens via API', function () {
        $user = User::factory()->create();
        $token = $user->createToken('My Token');

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/auth/tokens/{$token->accessToken->id}");

        $response->assertSuccessful();
        expect($user->tokens()->count())->toBe(0);
    });
});
