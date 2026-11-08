<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cron da hospedagem: * * * * * php artisan schedule:run
Schedule::command('love:reminders')->everyMinute();
Schedule::command('love:match-refresh')->hourly();
