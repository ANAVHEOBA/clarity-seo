<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

describe('Portal Branding', function () {
    it('returns branded portal metadata for a verified custom domain', function () {
        $tenant = Tenant::factory()->brandedPortal('agency.example.test')->create([
            'name' => 'Agency Workspace',
            'brand_name' => 'Agency Portal',
            'support_email' => 'support@agency.test',
            'reply_to_email' => 'hello@agency.test',
        ]);

        $response = $this
            ->getJson('http://agency.example.test/api/v1/portal');

        $response->assertOk()
            ->assertJsonPath('data.is_branded_portal', true)
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.branding.brand_name', 'Agency Portal')
            ->assertJsonPath('data.branding.support_email', 'support@agency.test')
            ->assertJsonPath('data.auth.public_signup_enabled', false);
    });

    it('limits tenant listing to the resolved branded tenant', function () {
        $user = User::factory()->create();
        $portalTenant = Tenant::factory()
            ->brandedPortal('portal.example.test')
            ->hasAttached($user, ['role' => 'owner'])
            ->create();
        Tenant::factory()->hasAttached($user, ['role' => 'owner'])->create();

        Sanctum::actingAs($user);

        $response = $this
            ->getJson('http://portal.example.test/api/v1/tenants');

        $response->assertOk();

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($portalTenant->id);
    });

    it('returns 404 when a branded host requests another tenant', function () {
        $user = User::factory()->create();
        $portalTenant = Tenant::factory()
            ->brandedPortal('restricted.example.test')
            ->hasAttached($user, ['role' => 'owner'])
            ->create();
        $otherTenant = Tenant::factory()
            ->hasAttached($user, ['role' => 'owner'])
            ->create();

        Sanctum::actingAs($user);

        $response = $this
            ->getJson("http://restricted.example.test/api/v1/tenants/{$otherTenant->id}");

        $response->assertNotFound();

        expect($portalTenant->id)->not->toBe($otherTenant->id);
    });

    it('uses tenant branding defaults when generating reports', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create([
            'name' => 'Agency Workspace',
            'brand_name' => 'Agency Portal',
            'logo_url' => 'https://cdn.example.test/logo.png',
            'primary_color' => '#123456',
            'secondary_color' => '#abcdef',
            'support_email' => 'support@agency.test',
            'hide_vendor_branding' => true,
            'white_label_enabled' => true,
        ]);

        $tenant->users()->attach($user, ['role' => 'owner']);

        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Review::factory()->create([
            'location_id' => $location->id,
            'rating' => 5,
            'published_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'csv',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.branding.company_name', 'Agency Portal')
            ->assertJsonPath('data.branding.logo_url', 'https://cdn.example.test/logo.png')
            ->assertJsonPath('data.branding.primary_color', '#123456')
            ->assertJsonPath('data.branding.white_label', true);

        expect($response->json('data.branding.footer_text'))->toContain('Agency Portal');
        expect($response->json('data.branding.footer_text'))->toContain('support@agency.test');
    });

    it('renders tenant branding in embeds instead of the hard-coded vendor name', function () {
        $tenant = Tenant::factory()->create([
            'name' => 'Agency Workspace',
            'brand_name' => 'Agency Portal',
            'hide_vendor_branding' => false,
            'white_label_enabled' => false,
        ]);

        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'embed_key' => 'embed-branding-key',
        ]);

        Review::factory()->create([
            'location_id' => $location->id,
            'author_name' => 'Jane Doe',
            'rating' => 5,
        ]);

        $response = $this->get('/api/v1/embed/embed-branding-key/reviews');

        $response->assertOk();
        $response->assertSee('Agency Portal', false);
        $response->assertDontSee('Localmator', false);
    });
});

describe('Branded Portal Auth', function () {
    it('blocks public registration when the branded portal is invite-only', function () {
        Event::fake();

        Tenant::factory()->brandedPortal('invite-only.example.test')->create([
            'support_email' => 'help@agency.test',
            'public_signup_enabled' => false,
        ]);

        $response = $this
            ->postJson('http://invite-only.example.test/api/v1/auth/register', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'password' => 'SecurePassword123!',
                'password_confirmation' => 'SecurePassword123!',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('support_email', 'help@agency.test');

        $this->assertDatabaseMissing('users', [
            'email' => 'john@example.com',
        ]);
    });

    it('allows public registration on branded portals that enable signups and attaches the user to the tenant', function () {
        $tenant = Tenant::factory()->brandedPortal('signup.example.test')->create([
            'public_signup_enabled' => true,
        ]);

        $response = $this
            ->postJson('http://signup.example.test/api/v1/auth/register', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'password' => 'SecurePassword123!',
                'password_confirmation' => 'SecurePassword123!',
            ]);

        $response->assertCreated();

        $user = User::where('email', 'john@example.com')->firstOrFail();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);

        expect($user->fresh()->current_tenant_id)->toBe($tenant->id);
    });

    it('blocks login when the user does not belong to the branded tenant', function () {
        Tenant::factory()->brandedPortal('private.example.test')->create();

        User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
        ]);

        $response = $this
            ->postJson('http://private.example.test/api/v1/auth/login', [
                'email' => 'john@example.com',
                'password' => 'SecurePassword123!',
            ]);

        $response->assertForbidden()
            ->assertJson([
                'message' => 'This account does not have access to this portal.',
            ]);
    });

    it('switches the current tenant to the branded portal on login', function () {
        $portalTenant = Tenant::factory()->brandedPortal('login.example.test')->create();
        $otherTenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'SecurePassword123!',
            'current_tenant_id' => $otherTenant->id,
        ]);

        $portalTenant->users()->attach($user, ['role' => 'member']);
        $otherTenant->users()->attach($user, ['role' => 'member']);

        $response = $this
            ->postJson('http://login.example.test/api/v1/auth/login', [
                'email' => 'john@example.com',
                'password' => 'SecurePassword123!',
            ]);

        $response->assertOk();

        expect($user->fresh()->current_tenant_id)->toBe($portalTenant->id);
    });
});
