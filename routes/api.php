<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AIResponse\AIResponseController;
use App\Http\Controllers\Api\V1\AIResponse\BrandVoiceController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\Automation\AutomationWorkflowController;
use App\Http\Controllers\Api\V1\Embed\EmbedController;
use App\Http\Controllers\Api\V1\Listing\FacebookConnectController;
use App\Http\Controllers\Api\V1\Listing\GoogleMyBusinessController;
use App\Http\Controllers\Api\V1\Listing\ListingController;
use App\Http\Controllers\Api\V1\Location\LocationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportScheduleController;
use App\Http\Controllers\Api\V1\ReportTemplateController;
use App\Http\Controllers\Api\V1\Review\ReviewController;
use App\Http\Controllers\Api\V1\Sentiment\SentimentController;
use App\Http\Controllers\Api\V1\Tenant\InvitationController;
use App\Http\Controllers\Api\V1\Tenant\MemberController;
use App\Http\Controllers\Api\V1\Tenant\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public Google OAuth Callback (Specific URI requested)
Route::get('/auth/google/callback', [GoogleMyBusinessController::class, 'callback'])->name('api.auth.google.callback');

Route::prefix('v1')->group(function () {
    // Auth routes (guest)
    Route::prefix('auth')->group(function () {
        Route::post('/register', RegisterController::class)->name('api.auth.register');
        Route::post('/login', LoginController::class)->name('api.auth.login');

        Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
    });

    // Facebook Connect Routes (Public for callback, or protected for connect)
    Route::get('/facebook/callback', [FacebookConnectController::class, 'callback'])->name('api.v1.facebook.callback');

    // Public Embed Routes
    Route::get('/embed/{embedKey}/reviews', [EmbedController::class, 'getReviews'])->name('api.v1.embed.reviews');

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {
        // Tenants
        Route::apiResource('tenants', TenantController::class);
        Route::post('/tenants/{tenant}/switch', [TenantController::class, 'switch'])->name('tenants.switch');
        
        // Facebook Connect
        Route::get('/tenants/{tenant}/facebook/connect', [FacebookConnectController::class, 'connect'])->name('api.v1.facebook.connect');

        // Google My Business Connect
        Route::get('/tenants/{tenant}/google/connect', [GoogleMyBusinessController::class, 'connect'])->name('api.v1.google.connect');
        Route::post('/tenants/{tenant}/google/credentials', [GoogleMyBusinessController::class, 'storeCredential'])->name('api.v1.google.credentials.store');

        // Tenant Members
        Route::get('/tenants/{tenant}/members', [MemberController::class, 'index'])->name('tenants.members.index');
        Route::patch('/tenants/{tenant}/members/{member}', [MemberController::class, 'update'])->name('tenants.members.update');
        Route::delete('/tenants/{tenant}/members/{member}', [MemberController::class, 'destroy'])->name('tenants.members.destroy');
        Route::post('/tenants/{tenant}/leave', [MemberController::class, 'leave'])->name('tenants.leave');

        // Tenant Invitations
        Route::post('/tenants/{tenant}/invitations', [InvitationController::class, 'store'])->name('tenants.invitations.store');
        Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

        // Locations
        Route::post('/tenants/{tenant}/locations/bulk', [LocationController::class, 'bulkImport'])->name('tenants.locations.bulk-import');
        Route::delete('/tenants/{tenant}/locations/bulk', [LocationController::class, 'bulkDelete'])->name('tenants.locations.bulk-delete');
        Route::get('/tenants/{tenant}/locations', [LocationController::class, 'index'])->name('tenants.locations.index');
        Route::post('/tenants/{tenant}/locations', [LocationController::class, 'store'])->name('tenants.locations.store');
        Route::get('/tenants/{tenant}/locations/{location}', [LocationController::class, 'show'])->name('tenants.locations.show');
        Route::put('/tenants/{tenant}/locations/{location}', [LocationController::class, 'update'])->name('tenants.locations.update');
        Route::delete('/tenants/{tenant}/locations/{location}', [LocationController::class, 'destroy'])->name('tenants.locations.destroy');

        // Listings - Tenant Level
        Route::get('/tenants/{tenant}/listings', [ListingController::class, 'index'])->name('tenants.listings.index');
        Route::get('/tenants/{tenant}/listings/stats', [ListingController::class, 'stats'])->name('tenants.listings.stats');
        Route::get('/tenants/{tenant}/listings/platforms', [ListingController::class, 'platforms'])->name('tenants.listings.platforms');
        Route::get('/tenants/{tenant}/listings/{listing}', [ListingController::class, 'show'])->name('tenants.listings.show');
        Route::post('/tenants/{tenant}/listings/credentials', [ListingController::class, 'storeCredential'])->name('tenants.listings.credentials.store');
        Route::delete('/tenants/{tenant}/listings/credentials/{platform}', [ListingController::class, 'destroyCredential'])->name('tenants.listings.credentials.destroy');

        // Listings - Location Level
        Route::get('/tenants/{tenant}/locations/{location}/listings/stats', [ListingController::class, 'locationStats'])->name('tenants.locations.listings.stats');
        Route::post('/tenants/{tenant}/locations/{location}/listings/sync/{platform}', [ListingController::class, 'sync'])->name('tenants.locations.listings.sync');
        Route::post('/tenants/{tenant}/locations/{location}/listings/sync', [ListingController::class, 'syncAll'])->name('tenants.locations.listings.sync-all');
        Route::post('/tenants/{tenant}/locations/{location}/listings/publish/{platform}', [ListingController::class, 'publish'])->name('tenants.locations.listings.publish');
        Route::post('/tenants/{tenant}/locations/{location}/listings/publish', [ListingController::class, 'publishAll'])->name('tenants.locations.listings.publish-all');

        // Reviews
        Route::get('/tenants/{tenant}/reviews', [ReviewController::class, 'index'])->name('tenants.reviews.index');
        Route::get('/tenants/{tenant}/reviews/stats', [ReviewController::class, 'stats'])->name('tenants.reviews.stats');
        Route::get('/tenants/{tenant}/reviews/{review}', [ReviewController::class, 'show'])->name('tenants.reviews.show');

        // Location Reviews
        Route::get('/tenants/{tenant}/locations/{location}/reviews', [ReviewController::class, 'locationIndex'])->name('tenants.locations.reviews.index');
        Route::get('/tenants/{tenant}/locations/{location}/reviews/stats', [ReviewController::class, 'locationStats'])->name('tenants.locations.reviews.stats');
        Route::post('/tenants/{tenant}/locations/{location}/reviews/sync', [ReviewController::class, 'sync'])->name('tenants.locations.reviews.sync');

        // Review Responses
        Route::post('/tenants/{tenant}/reviews/{review}/response', [ReviewController::class, 'storeResponse'])->name('tenants.reviews.response.store');
        Route::put('/tenants/{tenant}/reviews/{review}/response', [ReviewController::class, 'updateResponse'])->name('tenants.reviews.response.update');
        Route::post('/tenants/{tenant}/reviews/{review}/response/publish', [ReviewController::class, 'publishResponse'])->name('tenants.reviews.response.publish');
        Route::delete('/tenants/{tenant}/reviews/{review}/response', [ReviewController::class, 'destroyResponse'])->name('tenants.reviews.response.destroy');

        // Sentiment Analysis - Tenant Level
        Route::get('/tenants/{tenant}/sentiment', [SentimentController::class, 'stats'])->name('tenants.sentiment.stats');
        Route::get('/tenants/{tenant}/sentiment/topics', [SentimentController::class, 'topics'])->name('tenants.sentiment.topics');
        Route::get('/tenants/{tenant}/sentiment/keywords', [SentimentController::class, 'keywords'])->name('tenants.sentiment.keywords');
        Route::get('/tenants/{tenant}/sentiment/emotions', [SentimentController::class, 'emotions'])->name('tenants.sentiment.emotions');
        Route::get('/tenants/{tenant}/sentiment/trends', [SentimentController::class, 'trends'])->name('tenants.sentiment.trends');
        Route::get('/tenants/{tenant}/sentiment/compare', [SentimentController::class, 'compare'])->name('tenants.sentiment.compare');
        Route::get('/tenants/{tenant}/sentiment/export', [SentimentController::class, 'export'])->name('tenants.sentiment.export');

        // Sentiment Analysis - Location Level
        Route::get('/tenants/{tenant}/locations/{location}/sentiment', [SentimentController::class, 'locationStats'])->name('tenants.locations.sentiment.stats');
        Route::get('/tenants/{tenant}/locations/{location}/sentiment/topics', [SentimentController::class, 'locationTopics'])->name('tenants.locations.sentiment.topics');
        Route::get('/tenants/{tenant}/locations/{location}/sentiment/keywords', [SentimentController::class, 'locationKeywords'])->name('tenants.locations.sentiment.keywords');
        Route::get('/tenants/{tenant}/locations/{location}/sentiment/emotions', [SentimentController::class, 'locationEmotions'])->name('tenants.locations.sentiment.emotions');
        Route::get('/tenants/{tenant}/locations/{location}/sentiment/trends', [SentimentController::class, 'locationTrends'])->name('tenants.locations.sentiment.trends');
        Route::post('/tenants/{tenant}/locations/{location}/sentiment/analyze', [SentimentController::class, 'analyzeLocation'])->name('tenants.locations.sentiment.analyze');

        // Sentiment Analysis - Review Level
        Route::get('/tenants/{tenant}/reviews/{review}/sentiment', [SentimentController::class, 'showReviewSentiment'])->name('tenants.reviews.sentiment.show');
        Route::post('/tenants/{tenant}/reviews/{review}/analyze', [SentimentController::class, 'analyzeReview'])->name('tenants.reviews.sentiment.analyze');

        // Brand Voice Templates
        Route::get('/tenants/{tenant}/brand-voices', [BrandVoiceController::class, 'index'])->name('tenants.brand-voices.index');
        Route::post('/tenants/{tenant}/brand-voices', [BrandVoiceController::class, 'store'])->name('tenants.brand-voices.store');
        Route::get('/tenants/{tenant}/brand-voices/{brandVoice}', [BrandVoiceController::class, 'show'])->name('tenants.brand-voices.show');
        Route::put('/tenants/{tenant}/brand-voices/{brandVoice}', [BrandVoiceController::class, 'update'])->name('tenants.brand-voices.update');
        Route::delete('/tenants/{tenant}/brand-voices/{brandVoice}', [BrandVoiceController::class, 'destroy'])->name('tenants.brand-voices.destroy');

        // AI Response - Tenant Level
        Route::get('/tenants/{tenant}/ai-response/stats', [AIResponseController::class, 'stats'])->name('tenants.ai-response.stats');
        Route::get('/tenants/{tenant}/ai-response/usage', [AIResponseController::class, 'usage'])->name('tenants.ai-response.usage');
        Route::post('/tenants/{tenant}/reviews/ai-response/bulk', [AIResponseController::class, 'bulkGenerate'])->name('tenants.reviews.ai-response.bulk');

        // AI Response - Location Level
        Route::get('/tenants/{tenant}/locations/{location}/ai-response/stats', [AIResponseController::class, 'locationStats'])->name('tenants.locations.ai-response.stats');
        Route::post('/tenants/{tenant}/locations/{location}/reviews/ai-response/bulk', [AIResponseController::class, 'bulkGenerateForLocation'])->name('tenants.locations.reviews.ai-response.bulk');

        // AI Response - Review Level
        Route::post('/tenants/{tenant}/reviews/{review}/ai-response', [AIResponseController::class, 'generate'])->name('tenants.reviews.ai-response.generate');
        Route::post('/tenants/{tenant}/reviews/{review}/ai-response/regenerate', [AIResponseController::class, 'regenerate'])->name('tenants.reviews.ai-response.regenerate');
        Route::get('/tenants/{tenant}/reviews/{review}/ai-response/history', [AIResponseController::class, 'history'])->name('tenants.reviews.ai-response.history');
        Route::post('/tenants/{tenant}/reviews/{review}/response/approve', [AIResponseController::class, 'approve'])->name('tenants.reviews.response.approve');
        Route::post('/tenants/{tenant}/reviews/{review}/response/reject', [AIResponseController::class, 'reject'])->name('tenants.reviews.response.reject');
        Route::post('/tenants/{tenant}/reviews/{review}/response/suggestions', [AIResponseController::class, 'suggestions'])->name('tenants.reviews.response.suggestions');

        // Reports
        Route::get('/tenants/{tenant}/reports', [ReportController::class, 'index'])->name('api.v1.reports.index');
        Route::post('/tenants/{tenant}/reports', [ReportController::class, 'store'])->name('api.v1.reports.store');
        Route::get('/tenants/{tenant}/reports/{report}', [ReportController::class, 'show'])->name('api.v1.reports.show');
        Route::get('/tenants/{tenant}/reports/{report}/status', [ReportController::class, 'status'])->name('api.v1.reports.status');
        Route::get('/tenants/{tenant}/reports/{report}/download', [ReportController::class, 'download'])->name('api.v1.reports.download');
        Route::delete('/tenants/{tenant}/reports/{report}', [ReportController::class, 'destroy'])->name('api.v1.reports.destroy');

        // Report Schedules
        Route::get('/tenants/{tenant}/report-schedules', [ReportScheduleController::class, 'index'])->name('api.v1.report-schedules.index');
        Route::post('/tenants/{tenant}/report-schedules', [ReportScheduleController::class, 'store'])->name('api.v1.report-schedules.store');
        Route::get('/tenants/{tenant}/report-schedules/{reportSchedule}', [ReportScheduleController::class, 'show'])->name('api.v1.report-schedules.show');
        Route::put('/tenants/{tenant}/report-schedules/{reportSchedule}', [ReportScheduleController::class, 'update'])->name('api.v1.report-schedules.update');
        Route::post('/tenants/{tenant}/report-schedules/{reportSchedule}/toggle', [ReportScheduleController::class, 'toggle'])->name('api.v1.report-schedules.toggle');
        Route::delete('/tenants/{tenant}/report-schedules/{reportSchedule}', [ReportScheduleController::class, 'destroy'])->name('api.v1.report-schedules.destroy');

        // Report Templates
        Route::get('/tenants/{tenant}/report-templates', [ReportTemplateController::class, 'index'])->name('api.v1.report-templates.index');
        Route::post('/tenants/{tenant}/report-templates', [ReportTemplateController::class, 'store'])->name('api.v1.report-templates.store');
        Route::get('/tenants/{tenant}/report-templates/{reportTemplate}', [ReportTemplateController::class, 'show'])->name('api.v1.report-templates.show');
        Route::put('/tenants/{tenant}/report-templates/{reportTemplate}', [ReportTemplateController::class, 'update'])->name('api.v1.report-templates.update');
        Route::delete('/tenants/{tenant}/report-templates/{reportTemplate}', [ReportTemplateController::class, 'destroy'])->name('api.v1.report-templates.destroy');

        // Automation Workflows
        Route::get('/tenants/{tenant}/automation/workflows', [AutomationWorkflowController::class, 'index'])->name('api.v1.automation.workflows.index');
        Route::post('/tenants/{tenant}/automation/workflows', [AutomationWorkflowController::class, 'store'])->name('api.v1.automation.workflows.store');
        Route::get('/tenants/{tenant}/automation/workflows/{workflow}', [AutomationWorkflowController::class, 'show'])->name('api.v1.automation.workflows.show');
        Route::put('/tenants/{tenant}/automation/workflows/{workflow}', [AutomationWorkflowController::class, 'update'])->name('api.v1.automation.workflows.update');
        Route::delete('/tenants/{tenant}/automation/workflows/{workflow}', [AutomationWorkflowController::class, 'destroy'])->name('api.v1.automation.workflows.destroy');
        Route::post('/tenants/{tenant}/automation/workflows/{workflow}/execute', [AutomationWorkflowController::class, 'execute'])->name('api.v1.automation.workflows.execute');
        Route::post('/tenants/{tenant}/automation/workflows/{workflow}/toggle', [AutomationWorkflowController::class, 'toggle'])->name('api.v1.automation.workflows.toggle');
        Route::get('/tenants/{tenant}/automation/workflows/{workflow}/executions', [AutomationWorkflowController::class, 'executions'])->name('api.v1.automation.workflows.executions');
        
        // Automation Stats & Metadata
        Route::get('/tenants/{tenant}/automation/stats', [AutomationWorkflowController::class, 'stats'])->name('api.v1.automation.stats');
        Route::get('/automation/actions', [AutomationWorkflowController::class, 'availableActions'])->name('api.v1.automation.actions');
        Route::get('/automation/triggers', [AutomationWorkflowController::class, 'availableTriggers'])->name('api.v1.automation.triggers');

        // Embed Widget
        Route::get('/tenants/{tenant}/embed/code', [EmbedController::class, 'generateCode'])->name('api.v1.embed.code');
        Route::get('/tenants/{tenant}/locations/{location}/embed/code', [EmbedController::class, 'generateCode'])->name('api.v1.embed.location.code');
        Route::get('/tenants/{tenant}/locations/{location}/embed/regenerate', [EmbedController::class, 'regenerateKey'])->name('api.v1.embed.regenerate');
    });
});
