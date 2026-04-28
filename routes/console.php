<?php

use App\Jobs\UploadPendingConversions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new UploadPendingConversions)
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('upload-pending-conversions')
    ->description('Push pending conversions to Google Ads');
