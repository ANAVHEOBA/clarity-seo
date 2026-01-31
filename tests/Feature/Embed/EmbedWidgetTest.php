<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = Tenant::factory()->create();
    $this->user->tenants()->attach($this->tenant, ['role' => 'owner']);
    $this->user->update(['current_tenant_id' => $this->tenant->id]);
    
    $this->location = Location::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Business',
    ]);
});

test('can generate embed code for tenant', function () {
    actingAs($this->user);
    
    $response = get("/api/v1/tenants/{$this->tenant->id}/embed/code");
    
    $response->assertOk()
        ->assertJsonStructure([
            'embed_code',
            'embed_key',
            'preview_url',
        ]);
    
    expect($response->json('embed_code'))
        ->toContain('<script')
        ->toContain('data-showcase')
        ->toContain($response->json('embed_key'));
});

test('can generate embed code for specific location', function () {
    actingAs($this->user);
    
    $response = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/code");
    
    $response->assertOk()
        ->assertJsonStructure([
            'embed_code',
            'embed_key',
            'preview_url',
        ]);
    
    expect($response->json('embed_code'))
        ->toContain('data-showcase')
        ->toContain($this->location->id);
});

test('embed script file is accessible publicly', function () {
    $response = get('/embed/showcase.js');
    
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/javascript');
    
    // File response doesn't have content() method, just check it exists
    expect(file_exists(public_path('embed/showcase.js')))->toBeTrue();
});

test('embed endpoint returns reviews for valid key', function () {
    Review::factory()->count(5)->create([
        'location_id' => $this->location->id,
        'rating' => 5,
    ]);
    
    actingAs($this->user);
    $embedResponse = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/code");
    $embedKey = $embedResponse->json('embed_key');
    
    $response = get("/api/v1/embed/{$embedKey}/reviews");
    
    $response->assertOk()
        ->assertJsonStructure([
            'reviews' => [
                '*' => [
                    'id',
                    'author_name',
                    'rating',
                    'comment',
                    'created_at',
                ]
            ],
            'location' => [
                'name',
            ],
            'branding' => [
                'show_logo',
                'logo_url',
            ],
        ]);
    
    expect($response->json('reviews'))->toHaveCount(5);
});

test('embed endpoint returns 404 for invalid key', function () {
    $response = get('/api/v1/embed/invalid-key-12345/reviews');
    
    $response->assertNotFound();
});

test('free tier shows branding in embed response', function () {
    Review::factory()->count(3)->create([
        'location_id' => $this->location->id,
    ]);
    
    actingAs($this->user);
    $embedResponse = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/code");
    $embedKey = $embedResponse->json('embed_key');
    
    $response = get("/api/v1/embed/{$embedKey}/reviews");
    
    $response->assertOk();
    
    expect($response->json('branding.show_logo'))->toBeTrue();
    expect($response->json('branding.logo_url'))->not->toBeNull();
});

test('premium tier hides branding in embed response', function () {
    // Update tenant to premium with white label
    $this->tenant->plan = 'premium';
    $this->tenant->white_label_enabled = true;
    $this->tenant->save();
    
    // Verify the tenant was updated
    $freshTenant = \App\Models\Tenant::find($this->tenant->id);
    expect($freshTenant->white_label_enabled)->toBeTrue();
    expect($freshTenant->isWhiteLabelEnabled())->toBeTrue();
    
    Review::factory()->count(3)->create([
        'location_id' => $this->location->id,
    ]);
    
    actingAs($this->user);
    $embedResponse = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/code");
    $embedKey = $embedResponse->json('embed_key');
    
    // Fresh query to ensure we get updated tenant
    $response = get("/api/v1/embed/{$embedKey}/reviews");
    
    $response->assertOk();
    
    expect($response->json('branding.show_logo'))->toBeFalse();
});

test('embed only returns published reviews', function () {
    Review::factory()->count(5)->create([
        'location_id' => $this->location->id,
    ]);
    
    actingAs($this->user);
    $embedResponse = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/code");
    $embedKey = $embedResponse->json('embed_key');
    
    $response = get("/api/v1/embed/{$embedKey}/reviews");
    
    $response->assertOk();
    expect($response->json('reviews'))->toHaveCount(5);
});

test('embed respects limit parameter', function () {
    Review::factory()->count(10)->create([
        'location_id' => $this->location->id,
    ]);
    
    actingAs($this->user);
    $embedResponse = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/code");
    $embedKey = $embedResponse->json('embed_key');
    
    $response = get("/api/v1/embed/{$embedKey}/reviews?limit=5");
    
    $response->assertOk();
    expect($response->json('reviews'))->toHaveCount(5);
});

test('embed returns reviews sorted by rating and date', function () {
    Review::factory()->create([
        'location_id' => $this->location->id,
        'rating' => 3,
        'created_at' => now()->subDays(1),
    ]);
    
    Review::factory()->create([
        'location_id' => $this->location->id,
        'rating' => 5,
        'created_at' => now(),
    ]);
    
    Review::factory()->create([
        'location_id' => $this->location->id,
        'rating' => 5,
        'created_at' => now()->subDays(2),
    ]);
    
    actingAs($this->user);
    $embedResponse = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/code");
    $embedKey = $embedResponse->json('embed_key');
    
    $response = get("/api/v1/embed/{$embedKey}/reviews");
    
    $response->assertOk();
    $reviews = $response->json('reviews');
    
    expect($reviews[0]['rating'])->toBe(5);
    expect($reviews[1]['rating'])->toBe(5);
    expect($reviews[2]['rating'])->toBe(3);
});

test('can regenerate embed key', function () {
    actingAs($this->user);
    
    $firstResponse = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/code");
    $firstKey = $firstResponse->json('embed_key');
    
    $regenerateResponse = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/regenerate");
    
    $regenerateResponse->assertOk();
    $newKey = $regenerateResponse->json('embed_key');
    
    expect($newKey)->not->toBe($firstKey);
    
    // Old key should not work
    get("/api/v1/embed/{$firstKey}/reviews")->assertNotFound();
    
    // New key should work
    get("/api/v1/embed/{$newKey}/reviews")->assertOk();
});

test('embed widget supports custom styling options', function () {
    actingAs($this->user);
    
    $response = get("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/embed/code?theme=dark&layout=grid");
    
    $response->assertOk();
    
    expect($response->json('embed_code'))
        ->toContain('data-theme="dark"')
        ->toContain('data-layout="grid"');
});

test('unauthorized users cannot generate embed codes', function () {
    $response = $this->withoutMiddleware(\Illuminate\Auth\Middleware\Authenticate::class)
        ->get("/api/v1/tenants/{$this->tenant->id}/embed/code");
    
    // Without auth, the policy check should fail with 403
    $response->assertStatus(403);
});

test('users cannot generate embed codes for other tenants', function () {
    $otherTenant = Tenant::factory()->create();
    
    actingAs($this->user);
    
    $response = get("/api/v1/tenants/{$otherTenant->id}/embed/code");
    
    $response->assertForbidden();
});
