<?php

use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Listing\FacebookService;
use App\Services\Review\ReviewService;
use Illuminate\Support\Facades\Http;

uses()->group('integration', 'facebook');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = Tenant::factory()->create();
    $this->user->tenants()->attach($this->tenant, ['role' => 'owner']);
    $this->user->update(['current_tenant_id' => $this->tenant->id]);

    $this->facebookService = app(FacebookService::class);
    $this->reviewService = app(ReviewService::class);
});

test('it can store credentials for multiple Facebook pages', function () {
    // Store credentials for Page A
    $credentialA = $this->facebookService->storeCredentials(
        $this->tenant,
        'access_token_page_a',
        'page_id_123',
        'page_access_token_a',
        ['pages_read_engagement', 'pages_manage_engagement']
    );

    expect($credentialA)->toBeInstanceOf(PlatformCredential::class);
    expect($credentialA->external_id)->toBe('page_id_123');
    expect($credentialA->getPageId())->toBe('page_id_123');

    // Store credentials for Page B
    $credentialB = $this->facebookService->storeCredentials(
        $this->tenant,
        'access_token_page_b',
        'page_id_456',
        'page_access_token_b',
        ['pages_read_engagement', 'pages_manage_engagement']
    );

    expect($credentialB)->toBeInstanceOf(PlatformCredential::class);
    expect($credentialB->external_id)->toBe('page_id_456');
    expect($credentialB->id)->not->toBe($credentialA->id); // Different records

    // Verify both credentials exist in database
    $credentials = PlatformCredential::where('tenant_id', $this->tenant->id)
        ->where('platform', 'facebook')
        ->get();

    expect($credentials)->toHaveCount(2);
    expect($credentials->pluck('external_id')->toArray())->toContain('page_id_123', 'page_id_456');
});

test('it updates existing credential when same page is stored again', function () {
    // Store credentials for Page A
    $credentialA = $this->facebookService->storeCredentials(
        $this->tenant,
        'old_access_token',
        'page_id_123',
        'old_page_token'
    );

    $firstId = $credentialA->id;

    // Update credentials for same page
    $credentialUpdated = $this->facebookService->storeCredentials(
        $this->tenant,
        'new_access_token',
        'page_id_123',
        'new_page_token'
    );

    expect($credentialUpdated->id)->toBe($firstId); // Same record
    expect($credentialUpdated->access_token)->toBe('new_access_token');
    expect($credentialUpdated->metadata['page_access_token'])->toBe('new_page_token');

    // Verify only one credential exists
    $count = PlatformCredential::where('tenant_id', $this->tenant->id)
        ->where('platform', 'facebook')
        ->where('external_id', 'page_id_123')
        ->count();

    expect($count)->toBe(1);
});

test('it syncs reviews from correct Facebook page for each location', function () {
    // Create two locations with different Facebook pages
    $locationA = Location::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Store A',
        'facebook_page_id' => 'page_id_123',
    ]);

    $locationB = Location::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Store B',
        'facebook_page_id' => 'page_id_456',
    ]);

    // Store credentials for both pages
    $this->facebookService->storeCredentials(
        $this->tenant,
        'token_a',
        'page_id_123',
        'page_token_a'
    );

    $this->facebookService->storeCredentials(
        $this->tenant,
        'token_b',
        'page_id_456',
        'page_token_b'
    );

    // Mock Facebook API responses
    Http::fake([
        '*/page_id_123/ratings*' => Http::response([
            'data' => [
                [
                    'rating' => 5,
                    'review_text' => 'Great service at Store A!',
                    'created_time' => '2024-01-15T10:00:00+0000',
                    'open_graph_story' => ['id' => 'review_a_1'],
                    'reviewer' => ['name' => 'John Doe'],
                ],
            ],
        ]),
        '*/page_id_456/ratings*' => Http::response([
            'data' => [
                [
                    'rating' => 4,
                    'review_text' => 'Good experience at Store B!',
                    'created_time' => '2024-01-16T10:00:00+0000',
                    'open_graph_story' => ['id' => 'review_b_1'],
                    'reviewer' => ['name' => 'Jane Smith'],
                ],
            ],
        ]),
    ]);

    // Sync reviews for both locations
    $this->reviewService->syncReviewsForLocation($locationA);
    $this->reviewService->syncReviewsForLocation($locationB);

    // Verify Store A got reviews from Page A
    $reviewsA = $locationA->reviews()->where('platform', 'facebook')->get();
    expect($reviewsA)->toHaveCount(1);
    expect($reviewsA->first()->content)->toBe('Great service at Store A!');
    expect($reviewsA->first()->external_id)->toBe('review_a_1');

    // Verify Store B got reviews from Page B
    $reviewsB = $locationB->reviews()->where('platform', 'facebook')->get();
    expect($reviewsB)->toHaveCount(1);
    expect($reviewsB->first()->content)->toBe('Good experience at Store B!');
    expect($reviewsB->first()->external_id)->toBe('review_b_1');
});

test('it does not sync if location has no facebook_page_id', function () {
    $location = Location::factory()->create([
        'tenant_id' => $this->tenant->id,
        'facebook_page_id' => null, // No Facebook page
    ]);

    // Store a Facebook credential
    $this->facebookService->storeCredentials(
        $this->tenant,
        'token',
        'page_id_123'
    );

    Http::fake();

    // Try to sync
    $this->reviewService->syncReviewsForLocation($location);

    // Verify no HTTP requests were made
    Http::assertNothingSent();

    // Verify no Facebook reviews were created
    expect($location->reviews()->where('platform', 'facebook')->count())->toBe(0);
});

test('it does not sync if credential does not match location facebook_page_id', function () {
    // Location has Page A
    $location = Location::factory()->create([
        'tenant_id' => $this->tenant->id,
        'facebook_page_id' => 'page_id_123',
    ]);

    // But only Page B credential exists
    $this->facebookService->storeCredentials(
        $this->tenant,
        'token',
        'page_id_456' // Different page
    );

    Http::fake();

    // Try to sync
    $this->reviewService->syncReviewsForLocation($location);

    // Verify no HTTP requests were made (no matching credential)
    Http::assertNothingSent();

    // Verify no Facebook reviews were created
    expect($location->reviews()->where('platform', 'facebook')->count())->toBe(0);
});

test('it retrieves correct credential by external_id', function () {
    // Store credentials for multiple pages
    $this->facebookService->storeCredentials($this->tenant, 'token_a', 'page_123');
    $this->facebookService->storeCredentials($this->tenant, 'token_b', 'page_456');
    $this->facebookService->storeCredentials($this->tenant, 'token_c', 'page_789');

    // Retrieve specific credential by external_id
    $credential = PlatformCredential::where('tenant_id', $this->tenant->id)
        ->where('platform', 'facebook')
        ->where('external_id', 'page_456')
        ->first();

    expect($credential)->not->toBeNull();
    expect($credential->access_token)->toBe('token_b');
    expect($credential->getPageId())->toBe('page_456');
});

test('multiple tenants can have same page_id without conflict', function () {
    $tenant2 = Tenant::factory()->create();

    // Tenant 1 stores Page A
    $credential1 = $this->facebookService->storeCredentials(
        $this->tenant,
        'token_tenant1',
        'page_id_shared'
    );

    // Tenant 2 stores same Page A
    $credential2 = $this->facebookService->storeCredentials(
        $tenant2,
        'token_tenant2',
        'page_id_shared'
    );

    // Both should exist
    expect($credential1->id)->not->toBe($credential2->id);
    expect($credential1->tenant_id)->toBe($this->tenant->id);
    expect($credential2->tenant_id)->toBe($tenant2->id);
    expect($credential1->external_id)->toBe('page_id_shared');
    expect($credential2->external_id)->toBe('page_id_shared');
});
