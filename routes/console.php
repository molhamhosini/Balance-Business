<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily automatic backup at 02:00 (Asia/Dubai). Needs the Hostinger cron: * * * * * php artisan schedule:run
Illuminate\Support\Facades\Schedule::command('backup:database')->dailyAt('02:00')->timezone('Asia/Dubai');
