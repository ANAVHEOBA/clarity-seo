<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewResponse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->users()->attach($this->user, ['role' => 'owner']);
    $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->actingAs($this->user);
});

/*
|--------------------------------------------------------------------------
| Generate AI Response - Single Review
|--------------------------------------------------------------------------
*/

describe('Generate AI Response', function () {
    it('generates an AI response for a review', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Great service, very friendly staff!',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you so much for your wonderful review! We are thrilled to hear that you enjoyed our service and found our staff friendly. We look forward to serving you again soon!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'content',
                    'status',
                    'ai_generated',
                    'tone',
                    'language',
                    'created_at',
                ],
            ])
            ->assertJsonPath('data.ai_generated', true)
            ->assertJsonPath('data.status', 'draft');
    });

    it('generates response with professional tone', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Good experience overall.',
            'rating' => 4,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'We appreciate your feedback and are pleased to learn that your experience met your expectations. Should you have any further comments, please do not hesitate to reach out.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'tone' => 'professional',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.tone', 'professional');
    });

    it('generates response with friendly tone', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Loved it!',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Yay! So happy you loved it! 😊 Can\'t wait to see you again!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'tone' => 'friendly',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.tone', 'friendly');
    });

    it('generates response with apologetic tone for negative reviews', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Terrible experience, waited for 2 hours!',
            'rating' => 1,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'We sincerely apologize for the unacceptable wait time you experienced. This falls far below our standards, and we are taking immediate steps to ensure this does not happen again. Please contact us directly so we can make this right.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'tone' => 'apologetic',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.tone', 'apologetic');
    });

    it('generates response with empathetic tone', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Service was okay but the staff seemed stressed.',
            'rating' => 3,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for sharing your experience with us. We understand how important it is to feel welcomed, and we appreciate your patience. We are working to support our team better so they can provide the warm service you deserve.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'tone' => 'empathetic',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.tone', 'empathetic');
    });

    it('validates tone parameter', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'tone' => 'invalid_tone',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['tone']);
    });

    it('defaults to professional tone when not specified', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Nice place.',
            'rating' => 4,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for your feedback.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertSuccessful()
            ->assertJsonPath('data.tone', 'professional');
    });
});

/*
|--------------------------------------------------------------------------
| Multi-Language Support
|--------------------------------------------------------------------------
*/

describe('Multi-Language AI Response', function () {
    it('generates response in specified language', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Excelente servicio!',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '¡Muchas gracias por su maravillosa reseña! Nos alegra saber que disfrutó de nuestro servicio. ¡Esperamos verle pronto!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'language' => 'es',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.language', 'es');
    });

    it('auto-detects review language when not specified', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Très bon service, je recommande!',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Merci beaucoup pour votre avis! Nous sommes ravis que vous ayez apprécié notre service.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'auto_detect_language' => true,
        ]);

        $response->assertSuccessful();
    });

    it('generates response in German', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Sehr guter Service!',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Vielen Dank für Ihre wunderbare Bewertung! Wir freuen uns, dass Sie unseren Service genossen haben.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'language' => 'de',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.language', 'de');
    });

    it('generates response in Japanese', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'とても良いサービスでした！',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'ご評価いただきありがとうございます！またのご来店をお待ちしております。',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'language' => 'ja',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.language', 'ja');
    });

    it('validates language code', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'language' => 'invalid',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['language']);
    });
});

/*
|--------------------------------------------------------------------------
| Brand Voice Templates
|--------------------------------------------------------------------------
*/

describe('Brand Voice Templates CRUD', function () {
    it('creates a brand voice template', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'Corporate Voice',
            'description' => 'Formal and professional tone for enterprise clients',
            'tone' => 'professional',
            'guidelines' => 'Always use formal language. Avoid slang. Address customer concerns directly.',
            'example_responses' => [
                'Thank you for your valuable feedback. We appreciate your business.',
                'We apologize for any inconvenience and are committed to resolving this matter.',
            ],
            'is_default' => true,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'tone',
                    'guidelines',
                    'example_responses',
                    'is_default',
                    'created_at',
                ],
            ])
            ->assertJsonPath('data.name', 'Corporate Voice')
            ->assertJsonPath('data.is_default', true);
    });

    it('lists brand voice templates for tenant', function () {
        // Create templates via API or factory
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'Casual Voice',
            'tone' => 'friendly',
            'guidelines' => 'Be casual and approachable.',
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'Formal Voice',
            'tone' => 'professional',
            'guidelines' => 'Maintain professionalism.',
        ]);

        Http::fake(); // Prevent actual API calls

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/brand-voices");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'tone', 'guidelines'],
                ],
            ]);
    });

    it('shows a specific brand voice template', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'Test Voice',
            'tone' => 'friendly',
            'guidelines' => 'Test guidelines.',
        ]);

        $templateId = $createResponse->json('data.id');

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/brand-voices/{$templateId}");

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Test Voice');
    });

    it('updates a brand voice template', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'Original Name',
            'tone' => 'professional',
            'guidelines' => 'Original guidelines.',
        ]);

        $templateId = $createResponse->json('data.id');

        $response = $this->putJson("/api/v1/tenants/{$this->tenant->id}/brand-voices/{$templateId}", [
            'name' => 'Updated Name',
            'guidelines' => 'Updated guidelines.',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.guidelines', 'Updated guidelines.');
    });

    it('deletes a brand voice template', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'To Delete',
            'tone' => 'friendly',
            'guidelines' => 'Will be deleted.',
        ]);

        $templateId = $createResponse->json('data.id');

        $response = $this->deleteJson("/api/v1/tenants/{$this->tenant->id}/brand-voices/{$templateId}");

        $response->assertNoContent();

        $this->getJson("/api/v1/tenants/{$this->tenant->id}/brand-voices/{$templateId}")
            ->assertNotFound();
    });

    it('validates required fields when creating brand voice', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'tone', 'guidelines']);
    });

    it('prevents duplicate default brand voice', function () {
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'First Default',
            'tone' => 'professional',
            'guidelines' => 'Guidelines.',
            'is_default' => true,
        ]);

        // Creating second default should unset the first
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'Second Default',
            'tone' => 'friendly',
            'guidelines' => 'Other guidelines.',
            'is_default' => true,
        ]);

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/brand-voices");

        $defaults = collect($response->json('data'))->where('is_default', true);
        expect($defaults)->toHaveCount(1);
        expect($defaults->first()['name'])->toBe('Second Default');
    });
});

/*
|--------------------------------------------------------------------------
| Generate with Brand Voice
|--------------------------------------------------------------------------
*/

describe('Generate AI Response with Brand Voice', function () {
    it('generates response using brand voice template', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'Luxury Brand',
            'tone' => 'professional',
            'guidelines' => 'Use elegant language. Mention our commitment to excellence. Always thank for choosing us.',
            'example_responses' => [
                'We are honored by your gracious feedback and remain committed to exceeding your expectations.',
            ],
        ]);

        $brandVoiceId = $createResponse->json('data.id');

        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Amazing service!',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'We are deeply honored by your gracious feedback. Your recognition of our service affirms our unwavering commitment to excellence. Thank you for choosing us.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'brand_voice_id' => $brandVoiceId,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.brand_voice_id', $brandVoiceId);
    });

    it('uses default brand voice when none specified', function () {
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/brand-voices", [
            'name' => 'Default Voice',
            'tone' => 'friendly',
            'guidelines' => 'Be warm and welcoming.',
            'is_default' => true,
        ]);

        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Good experience.',
            'rating' => 4,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thanks so much for the kind words! We loved having you!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertSuccessful();
    });

    it('rejects invalid brand voice id', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'brand_voice_id' => 99999,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['brand_voice_id']);
    });

    it('rejects brand voice from another tenant', function () {
        $otherTenant = Tenant::factory()->create();
        $otherTenant->users()->attach($this->user, ['role' => 'owner']);

        // Create brand voice for other tenant
        $createResponse = $this->postJson("/api/v1/tenants/{$otherTenant->id}/brand-voices", [
            'name' => 'Other Tenant Voice',
            'tone' => 'professional',
            'guidelines' => 'Guidelines.',
        ]);

        $otherBrandVoiceId = $createResponse->json('data.id');

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'brand_voice_id' => $otherBrandVoiceId,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['brand_voice_id']);
    });
});

/*
|--------------------------------------------------------------------------
| Bulk AI Response Generation
|--------------------------------------------------------------------------
*/

describe('Bulk AI Response Generation', function () {
    it('generates AI responses for multiple reviews', function () {
        $reviews = Review::factory()->count(3)->create([
            'location_id' => $this->location->id,
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for your wonderful feedback!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/ai-response/bulk", [
            'review_ids' => $reviews->pluck('id')->toArray(),
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'generated_count',
                    'skipped_count',
                    'failed_count',
                    'responses' => [
                        '*' => ['review_id', 'content', 'status'],
                    ],
                ],
            ])
            ->assertJsonPath('data.generated_count', 3);
    });

    it('generates bulk responses for location', function () {
        Review::factory()->count(5)->create([
            'location_id' => $this->location->id,
            'rating' => 4,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/reviews/ai-response/bulk");

        $response->assertSuccessful()
            ->assertJsonPath('data.generated_count', 5);
    });

    it('skips reviews that already have responses', function () {
        $reviewWithResponse = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewResponse::factory()->create(['review_id' => $reviewWithResponse->id]);

        $reviewWithoutResponse = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/ai-response/bulk", [
            'review_ids' => [$reviewWithResponse->id, $reviewWithoutResponse->id],
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.generated_count', 1)
            ->assertJsonPath('data.skipped_count', 1);
    });

    it('applies tone to all bulk responses', function () {
        $reviews = Review::factory()->count(2)->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thanks!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/ai-response/bulk", [
            'review_ids' => $reviews->pluck('id')->toArray(),
            'tone' => 'friendly',
        ]);

        $response->assertSuccessful();

        foreach ($response->json('data.responses') as $responseData) {
            expect($responseData['tone'])->toBe('friendly');
        }
    });

    it('limits bulk generation to prevent abuse', function () {
        $reviews = Review::factory()->count(55)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/ai-response/bulk", [
            'review_ids' => $reviews->pluck('id')->toArray(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['review_ids']);
    });

    it('generates bulk with force option to replace existing', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewResponse::factory()->create([
            'review_id' => $review->id,
            'content' => 'Old response',
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'New AI response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/ai-response/bulk", [
            'review_ids' => [$review->id],
            'force' => true,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.generated_count', 1)
            ->assertJsonPath('data.skipped_count', 0);
    });
});

/*
|--------------------------------------------------------------------------
| Response Regeneration
|--------------------------------------------------------------------------
*/

describe('Response Regeneration', function () {
    it('regenerates an AI response', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'First response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $firstResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");
        $firstContent = $firstResponse->json('data.content');

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Regenerated response with different wording',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $regenerateResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response/regenerate");

        $regenerateResponse->assertSuccessful();
        // Just verify it returns a response - content comparison isn't meaningful with HTTP fakes
        expect($regenerateResponse->json('data.content'))->toBeString();
    });

    it('regenerates with different tone', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Professional response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'tone' => 'professional',
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Hey thanks so much! You rock!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response/regenerate", [
            'tone' => 'friendly',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.tone', 'friendly');
    });

    it('regenerates with different language', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'English response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Réponse en français',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response/regenerate", [
            'language' => 'fr',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.language', 'fr');
    });

    it('preserves generation history', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Generate multiple times
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response/regenerate");
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response/regenerate");

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response/history");

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data');
    });
});

/*
|--------------------------------------------------------------------------
| Response Approval Workflow
|--------------------------------------------------------------------------
*/

describe('Response Approval Workflow', function () {
    it('creates AI response as draft', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'draft');
    });

    it('approves a draft AI response', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/response/approve");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'approved');
    });

    it('publishes an approved response', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/response/approve");

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/response/publish");

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonStructure([
                'data' => ['published_at'],
            ]);
    });

    it('rejects a draft response', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/response/reject", [
            'reason' => 'Tone is not appropriate for this customer',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.status', 'rejected');
    });

    it('allows editing before approval', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response = $this->putJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/response", [
            'content' => 'Edited AI response with human touch',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.content', 'Edited AI response with human touch');
    });

    it('cannot publish without approval', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/response/publish");

        $response->assertUnprocessable();
    });

    it('tracks approval metadata', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated response',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/response/approve");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'approved_at',
                    'approved_by',
                ],
            ])
            ->assertJsonPath('data.approved_by.id', $this->user->id);
    });
});

/*
|--------------------------------------------------------------------------
| Response Quality & Suggestions
|--------------------------------------------------------------------------
*/

describe('Response Quality', function () {
    it('provides quality score for generated response', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'The food was great but service was slow.',
            'rating' => 3,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for your feedback. We are pleased you enjoyed the food and apologize for the slow service. We are working to improve our response times.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'include_quality_score' => true,
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'quality_score',
                    'quality_feedback',
                ],
            ]);
    });

    it('suggests improvements for response', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewResponse::factory()->create([
            'review_id' => $review->id,
            'content' => 'ok thanks',
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'suggestions' => [
                                    'Add personalization by using customer name if available',
                                    'Include a call-to-action inviting them back',
                                    'Express gratitude more warmly',
                                ],
                                'improved_version' => 'Thank you so much for taking the time to share your feedback! We truly appreciate your visit and hope to welcome you back soon.',
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/response/suggestions");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'suggestions',
                    'improved_version',
                ],
            ]);
    });
});

/*
|--------------------------------------------------------------------------
| Context-Aware Responses
|--------------------------------------------------------------------------
*/

describe('Context-Aware AI Responses', function () {
    it('uses review sentiment for context', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Absolutely terrible experience!',
            'rating' => 1,
        ]);

        // Create sentiment analysis
        $review->sentiment()->create([
            'sentiment' => 'negative',
            'sentiment_score' => 0.1,
            'emotions' => ['angry' => 0.8, 'frustrated' => 0.7],
            'topics' => [
                ['topic' => 'service', 'sentiment' => 'negative', 'score' => 0.2],
            ],
            'analyzed_at' => now(),
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'We are deeply sorry for your terrible experience. This is not the standard we strive for, and we take your feedback very seriously. Please contact us directly so we can address your concerns and make things right.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'use_sentiment_context' => true,
        ]);

        $response->assertSuccessful();
    });

    it('includes location context in response', function () {
        $this->location->update([
            'name' => 'Downtown Coffee Shop',
            'address' => '123 Main Street',
        ]);

        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Great coffee!',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for visiting Downtown Coffee Shop! We are so glad you enjoyed our coffee. We hope to see you again at our Main Street location!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'include_location_context' => true,
        ]);

        $response->assertSuccessful();
    });

    it('references specific topics mentioned in review', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'The parking was terrible but the staff was amazing.',
            'rating' => 3,
        ]);

        $review->sentiment()->create([
            'sentiment' => 'mixed',
            'sentiment_score' => 0.5,
            'topics' => [
                ['topic' => 'parking', 'sentiment' => 'negative', 'score' => 0.2],
                ['topic' => 'staff', 'sentiment' => 'positive', 'score' => 0.9],
            ],
            'analyzed_at' => now(),
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for your feedback! We are thrilled our staff made a positive impression. We apologize for the parking difficulties and are exploring options to improve this for our guests.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'use_sentiment_context' => true,
        ]);

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Custom Instructions
|--------------------------------------------------------------------------
*/

describe('Custom Instructions', function () {
    it('accepts custom instructions for response generation', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Nice place.',
            'rating' => 4,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for your review! As a token of appreciation, please enjoy 10% off your next visit with code THANKYOU10.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'custom_instructions' => 'Include a 10% discount code THANKYOU10 for their next visit.',
        ]);

        $response->assertSuccessful();
    });

    it('limits custom instructions length', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'custom_instructions' => str_repeat('a', 1001),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_instructions']);
    });

    it('includes max length constraint for response', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thanks!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'max_length' => 50,
        ]);

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Error Handling
|--------------------------------------------------------------------------
*/

describe('AI Response Error Handling', function () {
    it('handles API failure gracefully', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => 'API Error'], 500),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'AI service temporarily unavailable',
            ]);
    });

    it('handles rate limiting', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => 'Rate limited'], 429),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertStatus(429)
            ->assertJson([
                'message' => 'AI service rate limit exceeded. Please try again later.',
            ]);
    });

    it('handles missing API key', function () {
        config(['openrouter.api_key' => null]);

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'AI service not configured',
            ]);
    });

    it('handles empty response from API', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'AI service returned empty response',
            ]);
    });

    it('handles malformed API response', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response(['unexpected' => 'format'], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertStatus(503);
    });

    it('handles timeout', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'AI service temporarily unavailable',
            ]);
    });
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

describe('AI Response Authorization', function () {
    it('requires authentication', function () {
        $this->app['auth']->forgetGuards();

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertUnauthorized();
    });

    it('requires tenant membership', function () {
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertForbidden();
    });

    it('validates review belongs to tenant', function () {
        $otherTenant = Tenant::factory()->create();
        $otherLocation = Location::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherReview = Review::factory()->create(['location_id' => $otherLocation->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$otherReview->id}/ai-response");

        $response->assertNotFound();
    });

    it('allows member to generate response', function () {
        $member = User::factory()->create();
        $this->tenant->users()->attach($member, ['role' => 'member']);
        $this->actingAs($member);

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Statistics & Analytics
|--------------------------------------------------------------------------
*/

describe('AI Response Statistics', function () {
    it('returns AI response statistics for tenant', function () {
        $reviews = Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        foreach ($reviews->take(6) as $review) {
            ReviewResponse::factory()->aiGenerated()->create(['review_id' => $review->id]);
        }

        foreach ($reviews->skip(6)->take(2) as $review) {
            ReviewResponse::factory()->create(['review_id' => $review->id, 'ai_generated' => false]);
        }

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/ai-response/stats");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'total_responses',
                    'ai_generated_count',
                    'human_written_count',
                    'ai_percentage',
                    'by_status',
                    'by_tone',
                ],
            ])
            ->assertJsonPath('data.ai_generated_count', 6)
            ->assertJsonPath('data.human_written_count', 2);
    });

    it('returns statistics for location', function () {
        $reviews = Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        foreach ($reviews as $review) {
            ReviewResponse::factory()->aiGenerated()->create(['review_id' => $review->id]);
        }

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/ai-response/stats");

        $response->assertSuccessful()
            ->assertJsonPath('data.ai_generated_count', 5);
    });

    it('returns usage over time', function () {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/ai-response/usage", [
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'date',
                        'generated_count',
                        'approved_count',
                        'published_count',
                    ],
                ],
            ]);
    });
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

describe('AI Response Edge Cases', function () {
    it('handles review with no content', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => null,
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for your 5-star rating! We appreciate your support.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertSuccessful();
    });

    it('handles review with very long content', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => str_repeat('This is a very detailed review. ', 100),
            'rating' => 4,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for your detailed feedback!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertSuccessful();
    });

    it('handles review with special characters', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Great! 👍 "Best" place ever!!! <script>alert("xss")</script>',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response->assertSuccessful();
    });

    it('handles review in non-Latin script', function () {
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => '素晴らしいサービスでした！また来ます。',
            'rating' => 5,
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'ご評価いただきありがとうございます！またのご来店をお待ちしております。',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response", [
            'language' => 'ja',
        ]);

        $response->assertSuccessful();
    });

    it('handles concurrent generation requests', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you!',
                        ],
                    ],
                ],
            ], 200),
        ]);

        // First request
        $response1 = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        // Second concurrent request should handle gracefully
        $response2 = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/ai-response");

        $response1->assertSuccessful();
        // Second should either succeed with same response or indicate already exists
        expect($response2->status())->toBeIn([200, 201, 409]);
    });
});
