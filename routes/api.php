<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Auth routes
    Route::prefix('auth')->group(function () {
        // Guest routes
        Route::post('/register', RegisterController::class)->name('api.auth.register');
        Route::post('/login', LoginController::class)->name('api.auth.login');

        // Email verification
        Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
    });
});
