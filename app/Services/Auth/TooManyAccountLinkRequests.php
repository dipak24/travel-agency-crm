<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Thrown when an account email (setup/reset link, email-change verification) is requested again
 * before the rate limit allows it. Callers show `getMessage()` to the user.
 */
class TooManyAccountLinkRequests extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(sprintf(
            'An email was sent to this account very recently. Please wait %s before sending another.',
            $retryAfterSeconds >= 60 ? ceil($retryAfterSeconds / 60).' minute(s)' : $retryAfterSeconds.' second(s)',
        ));
    }
}
