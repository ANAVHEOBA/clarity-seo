<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

describe('Two-Factor Authentication Setup', function () {
    it('displays the 2FA setup page', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/two-factor/setup');

        $response->assertSuccessful();
        $response->assertSee('Two-Factor Authentication');
    });

    it('generates a 2FA secret', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/two-factor/enable');

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'qr_code',
            'secret',
            'recovery_codes',
        ]);
    });

    it('confirms 2FA setup with valid code', function () {
        $user = User::factory()->create();
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        Cache::put("2fa_secret_{$user->id}", $secret, 600);

        $validCode = $google2fa->getCurrentOtp($secret);

        $response = $this->actingAs($user)->post('/two-factor/confirm', [
            'code' => $validCode,
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('status', 'two-factor-enabled');

        expect($user->fresh()->two_factor_secret)->not->toBeNull();
    });

    it('fails 2FA confirmation with invalid code', function () {
        $user = User::factory()->create();

        Cache::put("2fa_secret_{$user->id}", 'test-secret', 600);

        $response = $this->actingAs($user)->post('/two-factor/confirm', [
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');
    });
});

describe('Two-Factor Authentication Challenge', function () {
    it('displays 2FA challenge after login', function () {
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('test-secret'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response = $this->get('/two-factor/challenge');

        $response->assertSuccessful();
        $response->assertSee('Two-Factor Code');
    });

    it('authenticates with valid 2FA code', function () {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user = User::factory()->create([
            'two_factor_secret' => encrypt($secret),
        ]);

        session(['login.id' => $user->id, 'login.remember' => false]);

        $validCode = $google2fa->getCurrentOtp($secret);

        $response = $this->post('/two-factor/challenge', [
            'code' => $validCode,
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    });

    it('fails with invalid 2FA code', function () {
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('test-secret'),
        ]);

        session(['login.id' => $user->id, 'login.remember' => false]);

        $response = $this->post('/two-factor/challenge', [
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('authenticates with valid recovery code', function () {
        $recoveryCodes = [
            'AAAA-BBBB-CCCC',
            'DDDD-EEEE-FFFF',
        ];

        $user = User::factory()->create([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ]);

        session(['login.id' => $user->id, 'login.remember' => false]);

        $response = $this->post('/two-factor/challenge', [
            'recovery_code' => 'AAAA-BBBB-CCCC',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $remainingCodes = json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);
        expect($remainingCodes)->not->toContain('AAAA-BBBB-CCCC');
    });

    it('invalidates recovery code after use', function () {
        $recoveryCodes = ['AAAA-BBBB-CCCC'];

        $user = User::factory()->create([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ]);

        session(['login.id' => $user->id, 'login.remember' => false]);

        $this->post('/two-factor/challenge', [
            'recovery_code' => 'AAAA-BBBB-CCCC',
        ]);

        auth()->logout();
        session(['login.id' => $user->id, 'login.remember' => false]);

        $response = $this->post('/two-factor/challenge', [
            'recovery_code' => 'AAAA-BBBB-CCCC',
        ]);

        $response->assertSessionHasErrors('recovery_code');
    });
});

describe('Two-Factor Authentication Disable', function () {
    it('disables 2FA with password confirmation', function () {
        $user = User::factory()->create([
            'password' => 'SecurePassword123!',
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        ]);

        $response = $this->actingAs($user)->delete('/two-factor', [
            'password' => 'SecurePassword123!',
        ]);

        $response->assertRedirect('/profile');

        $user->refresh();
        expect($user->two_factor_secret)->toBeNull();
        expect($user->two_factor_recovery_codes)->toBeNull();
    });

    it('requires password to disable 2FA', function () {
        $user = User::factory()->create([
            'password' => 'SecurePassword123!',
            'two_factor_secret' => encrypt('test-secret'),
        ]);

        $response = $this->actingAs($user)->delete('/two-factor', [
            'password' => 'WrongPassword!',
        ]);

        $response->assertSessionHasErrors('password');
        expect($user->fresh()->two_factor_secret)->not->toBeNull();
    });
});

describe('Recovery Codes Regeneration', function () {
    it('regenerates recovery codes', function () {
        $oldCodes = ['OLD-CODE-1', 'OLD-CODE-2'];

        $user = User::factory()->create([
            'password' => 'SecurePassword123!',
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode($oldCodes)),
        ]);

        $response = $this->actingAs($user)->post('/two-factor/recovery-codes', [
            'password' => 'SecurePassword123!',
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure(['recovery_codes']);

        $newCodes = json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);
        expect($newCodes)->not->toBe($oldCodes);
    });
});
