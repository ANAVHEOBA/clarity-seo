<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:fetch-reviews')->hourly();
Schedule::command('automation:run')->everyMinute();
Schedule::command('app:send-scheduled-reports')->hourly();
Schedule::command('app:sync-listings')->daily();
