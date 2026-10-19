<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:send-due-service-reminders --channel=whatsapp --policy=single --limit=200')
    ->dailyAt('10:00');

Schedule::command('app:dispatch-scheduled-campaigns --limit=25')
    ->everyTenMinutes();

Schedule::command('schedules:fill --days=31')
    ->dailyAt('00:20');

Artisan::command('whatsapp:sync-templates', function () {
    app(\App\Services\WhatsAppTemplateManagerService::class)->refreshAutomatically();
    $this->info('WhatsApp template statuses refreshed.');
})->purpose('Refresh WhatsApp template approval statuses from the provider');

Schedule::command('whatsapp:sync-templates')->everyFiveMinutes()->withoutOverlapping();
