<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

describe('Profile View', function () {
    it('displays the profile page', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response->assertSuccessful();
        $response->assertSee($user->name);
        $response->assertSee($user->email);
    });

    it('requires authentication to view profile', function () {
        $response = $this->get('/profile');

        $response->assertRedirect('/login');
    });
});

describe('Profile Update', function () {
    it('allows users to update their name', function () {
        $user = User::factory()->create([
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'New Name',
            'email' => $user->email,
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('status', 'profile-updated');

        expect($user->fresh()->name)->toBe('New Name');
    });

    it('allows users to update their email', function () {
        $user = User::factory()->create([
            'email' => 'old@example.com',
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ]);

        $response->assertRedirect('/profile');

        expect($user->fresh()->email)->toBe('new@example.com');
    });

    it('resets email verification when email changes', function () {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ]);

        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    it('does not reset verification when email unchanged', function () {
        $verifiedAt = now();

        $user = User::factory()->create([
            'email' => 'john@example.com',
            'email_verified_at' => $verifiedAt,
        ]);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'New Name',
            'email' => 'john@example.com',
        ]);

        expect($user->fresh()->email_verified_at->timestamp)->toBe($verifiedAt->timestamp);
    });

    it('requires a valid email', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('email');
    });

    it('requires a unique email', function () {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'taken@example.com',
        ]);

        $response->assertSessionHasErrors('email');
    });

    it('allows keeping own email', function () {
        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'New Name',
            'email' => 'john@example.com',
        ]);

        $response->assertSessionDoesntHaveErrors('email');
    });
});

describe('Password Update', function () {
    it('allows users to update their password', function () {
        $user = User::factory()->create([
            'password' => 'CurrentPassword123!',
        ]);

        $response = $this->actingAs($user)->put('/password', [
            'current_password' => 'CurrentPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'password-updated');

        expect(Hash::check('NewPassword123!', $user->fresh()->password))->toBeTrue();
    });

    it('requires correct current password', function () {
        $user = User::factory()->create([
            'password' => 'CurrentPassword123!',
        ]);

        $response = $this->actingAs($user)->put('/password', [
            'current_password' => 'WrongPassword!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertSessionHasErrors('current_password');
    });

    it('requires password confirmation', function () {
        $user = User::factory()->create([
            'password' => 'CurrentPassword123!',
        ]);

        $response = $this->actingAs($user)->put('/password', [
            'current_password' => 'CurrentPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'DifferentPassword!',
        ]);

        $response->assertSessionHasErrors('password');
    });

    it('requires minimum password length', function () {
        $user = User::factory()->create([
            'password' => 'CurrentPassword123!',
        ]);

        $response = $this->actingAs($user)->put('/password', [
            'current_password' => 'CurrentPassword123!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    });
});

describe('Account Deletion', function () {
    it('allows users to delete their account', function () {
        $user = User::factory()->create([
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->actingAs($user)->delete('/profile', [
            'password' => 'SecurePassword123!',
        ]);

        $response->assertRedirect('/');
        $this->assertGuest();

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);
    });

    it('requires password confirmation to delete account', function () {
        $user = User::factory()->create([
            'password' => 'SecurePassword123!',
        ]);

        $response = $this->actingAs($user)->delete('/profile', [
            'password' => 'WrongPassword!',
        ]);

        $response->assertSessionHasErrors('password');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    });
});

describe('API Profile', function () {
    it('returns authenticated user profile', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/auth/user');

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    });

    it('updates profile via API', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/user', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ],
        ]);
    });

    it('updates password via API', function () {
        $user = User::factory()->create([
            'password' => 'CurrentPassword123!',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/password', [
            'current_password' => 'CurrentPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Password updated successfully',
        ]);
    });

    it('deletes account via API', function () {
        $user = User::factory()->create([
            'password' => 'SecurePassword123!',
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/auth/user', [
            'password' => 'SecurePassword123!',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Account deleted successfully',
        ]);

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);
    });

    it('returns 401 for unauthenticated requests', function () {
        $response = $this->getJson('/api/v1/auth/user');

        $response->assertUnauthorized();
    });
});
