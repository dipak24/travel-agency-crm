<?php

use App\Console\Commands\ExpireGiftVouchers;
use App\Console\Commands\SendBookingReminders;
use App\Console\Commands\SendScheduledCampaigns;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(ExpireGiftVouchers::class)->daily();
Schedule::command(SendBookingReminders::class)->dailyAt('08:00')->withoutOverlapping();
Schedule::command(SendScheduledCampaigns::class)->everyMinute()->withoutOverlapping();
