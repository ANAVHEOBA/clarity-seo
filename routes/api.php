<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AIResponse\AIResponseController;
use App\Http\Controllers\Api\V1\AIResponse\BrandVoiceController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
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

Route::prefix('v1')->group(function () {
    // Auth routes (guest)
    Route::prefix('auth')->group(function () {
        Route::post('/register', RegisterController::class)->name('api.auth.register');
        Route::post('/login', LoginController::class)->name('api.auth.login');

        Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
    });

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {
        // Tenants
        Route::apiResource('tenants', TenantController::class);
        Route::post('/tenants/{tenant}/switch', [TenantController::class, 'switch'])->name('tenants.switch');

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
    });
});
