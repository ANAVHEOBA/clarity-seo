<?php

declare(strict_types=1);

use App\Http\Controllers\FacebookWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

// Embed Widget JS
Route::get('/embed/showcase.js', function () {
    $path = public_path('embed/showcase.js');
    
    if (!file_exists($path)) {
        abort(404);
    }
    
    return response()->file($path, [
        'Content-Type' => 'application/javascript',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('embed.showcase');

// Facebook UI Routes
Route::get('/facebook/setup', [FacebookWebController::class, 'index'])->name('facebook.index');
Route::get('/facebook/auth', [FacebookWebController::class, 'redirect'])->name('facebook.auth');
Route::get('/facebook/callback', [FacebookWebController::class, 'callback'])->name('facebook.callback');
Route::post('/facebook/save', [FacebookWebController::class, 'store'])->name('facebook.save');
