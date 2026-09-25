<?php

namespace App\Console\Commands;

use App\Services\BookingReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:send-booking-reminders')]
#[Description('Email customers the documents / traveler details / balance-due reminders due today, per each tenant\'s schedule')]
class SendBookingReminders extends Command
{
    public function handle(BookingReminders $reminders): int
    {
        $count = $reminders->sendDue();

        $this->info("Sent {$count} booking reminder(s).");

        return self::SUCCESS;
    }
}
