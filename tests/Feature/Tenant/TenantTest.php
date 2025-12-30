<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('Tenant Creation', function () {
    it('allows authenticated users to create a tenant', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tenants', [
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'slug', 'created_at'],
        ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
        ]);
    });

    it('makes the creator an owner of the tenant', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tenants', [
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
        ]);

        $tenantId = $response->json('data.id');

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    });

    it('requires authentication to create a tenant', function () {
        $response = $this->postJson('/api/v1/tenants', [
            'name' => 'Acme Corporation',
        ]);

        $response->assertUnauthorized();
    });

    it('requires a tenant name', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tenants', [
            'name' => '',
            'slug' => 'acme-corp',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('name');
    });

    it('requires a unique slug', function () {
        Tenant::factory()->create(['slug' => 'acme-corp']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tenants', [
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('slug');
    });

    it('auto-generates slug if not provided', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tenants', [
            'name' => 'Acme Corporation',
        ]);

        $response->assertCreated();
        expect($response->json('data.slug'))->toBe('acme-corporation');
    });
});

describe('Tenant Retrieval', function () {
    it('returns tenant details for members', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}");

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
        ]);
    });

    it('returns 403 for non-members', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}");

        $response->assertForbidden();
    });

    it('lists all tenants for the user', function () {
        $user = User::factory()->create();
        $tenant1 = Tenant::factory()->hasAttached($user, ['role' => 'owner'])->create();
        $tenant2 = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        Tenant::factory()->create(); // Another tenant user is not part of

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/tenants');

        $response->assertSuccessful();
        $response->assertJsonCount(2, 'data');
    });
});

describe('Tenant Update', function () {
    it('allows owners to update tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'owner'])->create();
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tenants/{$tenant->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Updated Name',
        ]);
    });

    it('allows admins to update tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tenants/{$tenant->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertSuccessful();
    });

    it('denies members from updating tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tenants/{$tenant->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertForbidden();
    });
});

describe('Tenant Deletion', function () {
    it('allows owners to delete tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'owner'])->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/tenants/{$tenant->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    });

    it('denies admins from deleting tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/tenants/{$tenant->id}");

        $response->assertForbidden();
    });

    it('denies members from deleting tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/tenants/{$tenant->id}");

        $response->assertForbidden();
    });
});
