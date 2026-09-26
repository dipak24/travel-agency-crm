<?php

namespace App\Filament\Concerns;

use App\Services\Auth\TooManyAccountLinkRequests;
use Closure;
use Filament\Notifications\Notification;

/**
 * Runs an account-email send (App\Services\Auth\AccountSetupLinks / CustomerEmailChange) from a
 * Filament action and reports the outcome: sent, rate-limited, or mail failure.
 */
trait SendsAccountLinks
{
    /**
     * @param  Closure(): bool  $send
     */
    protected static function deliverAccountLink(Closure $send, string $successTitle, string $successBody): void
    {
        try {
            $sent = $send();
        } catch (TooManyAccountLinkRequests $e) {
            Notification::make()->title('Please wait')->body($e->getMessage())->warning()->send();

            return;
        }

        if (! $sent) {
            Notification::make()->title('Email failed to send')->body('Check the mail settings.')->danger()->send();

            return;
        }

        Notification::make()->title($successTitle)->body($successBody)->success()->send();
    }
}
