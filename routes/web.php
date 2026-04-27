<?php

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
