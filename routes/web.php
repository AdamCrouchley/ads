<?php

use App\Http\Controllers\Auth\GoogleAdsOAuthController;
use App\Http\Controllers\Webhooks\BookingConfirmedReceiver;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['throttle:60,1'])
    ->prefix('webhooks')
    ->group(function () {
        Route::post('/booking-confirmed', BookingConfirmedReceiver::class)
            ->name('webhooks.booking-confirmed');

        Route::get('/health', function () {
            return response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]);
        })->name('webhooks.health');
    });

// Google Ads OAuth — only authenticated dashboard users can initiate.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/auth/google/connect/{brand}', [GoogleAdsOAuthController::class, 'connect'])
        ->name('google-ads.connect');

    Route::get('/auth/google/callback', [GoogleAdsOAuthController::class, 'callback'])
        ->name('google-ads.callback');
});
