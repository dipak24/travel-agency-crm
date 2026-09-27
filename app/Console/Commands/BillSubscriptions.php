<?php

namespace App\Console\Commands;

use App\Services\SubscriptionBilling;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:bill-subscriptions')]
#[Description('Raise recurring platform invoices for tenant subscriptions whose next billing date has passed')]
class BillSubscriptions extends Command
{
    public function handle(SubscriptionBilling $billing): int
    {
        $count = $billing->billDue();

        $this->info("Created {$count} subscription invoice(s).");

        return self::SUCCESS;
    }
}
