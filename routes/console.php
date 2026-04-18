<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:send-due-service-reminders --channel=sms --policy=fallback --fallback=email --limit=200')
    ->dailyAt('10:00');

Schedule::command('app:dispatch-scheduled-campaigns --limit=25')
    ->everyTenMinutes();

Schedule::command('schedules:fill --days=31')
    ->dailyAt('00:20');
