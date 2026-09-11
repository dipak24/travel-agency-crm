<?php

namespace App\Support;

use App\Models\Payment;

final class PaymentReturnResult
{
    /**
     * @param  'completed'|'pending'|'failed'|'cancelled'  $status
     */
    public function __construct(
        public readonly string $status,
        public readonly ?Payment $payment = null,
        public readonly ?string $message = null,
    ) {}
}
