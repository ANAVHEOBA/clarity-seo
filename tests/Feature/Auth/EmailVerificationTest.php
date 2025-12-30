<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

describe('Email Verification Notice', function () {
    it('displays the email verification notice', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertSuccessful();
        $response->assertSee('Verify Email');
    });

    it('redirects verified users away from notice', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertRedirect('/dashboard');
    });
});

describe('Email Verification', function () {
    it('verifies email with valid signed URL', function () {
        Event::fake();

        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertRedirect('/dashboard');

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

        Event::assertDispatched(Verified::class);
    });

    it('fails with invalid hash', function () {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => 'invalid-hash']
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertForbidden();

        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('fails with expired signed URL', function () {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinutes(10),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertForbidden();

        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('fails when verifying different user', function () {
        $user1 = User::factory()->unverified()->create();
        $user2 = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user1->id, 'hash' => sha1($user1->email)]
        );

        $response = $this->actingAs($user2)->get($verificationUrl);

        $response->assertForbidden();
    });

    it('does not re-verify already verified email', function () {
        Event::fake();

        $user = User::factory()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        $response->assertRedirect('/dashboard');

        Event::assertNotDispatched(Verified::class);
    });
});

describe('Email Verification Resend', function () {
    it('resends verification email', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->post('/email/verification-notification');

        $response->assertRedirect();
        $response->assertSessionHas('status');

        Notification::assertSentTo($user, VerifyEmail::class);
    });

    it('does not resend to verified users', function () {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/email/verification-notification');

        $response->assertRedirect('/dashboard');

        Notification::assertNotSentTo($user, VerifyEmail::class);
    });

    it('rate limits resend requests', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/email/verification-notification');
        }

        $response = $this->actingAs($user)->post('/email/verification-notification');

        $response->assertStatus(429);
    });
});

describe('Verified Middleware', function () {
    it('allows verified users to access protected routes', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSuccessful();
    });

    it('redirects unverified users to verification notice', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect('/verify-email');
    });
});

describe('API Email Verification', function () {
    it('verifies email via API', function () {
        Event::fake();

        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->getJson($verificationUrl);

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Email verified successfully',
        ]);

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('resends verification email via API', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/auth/email/resend');

        $response->assertSuccessful();
        $response->assertJson([
            'message' => 'Verification email sent',
        ]);

        Notification::assertSentTo($user, VerifyEmail::class);
    });
});
