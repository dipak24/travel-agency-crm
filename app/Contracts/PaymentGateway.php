<?php

namespace App\Contracts;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\PaymentReturnResult;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function key(): string;

    /**
     * Whether the given invoice's tenant has this gateway enabled and configured.
     */
    public function isEnabledFor(Invoice $invoice): bool;

    /**
     * Create a gateway checkout/order for the given invoice and return the URL the customer's
     * browser should be redirected to in order to approve the payment. $returnUrl is where the
     * gateway should send the browser back afterwards — supplied by the caller (portal vs. public
     * link controllers use different routes) rather than assumed by the gateway itself.
     */
    public function startCheckout(Invoice $invoice, int $amount, string $returnUrl): string;

    /**
     * Handle the customer's browser returning from the gateway after approval. Some gateways
     * (PayPal) can synchronously confirm and record a completed Payment here; others only confirm
     * asynchronously via handleWebhook(), in which case this returns a 'pending' status and no
     * Payment yet — the browser return is UX only, never the source of truth for a gateway that
     * doesn't guarantee synchronous confirmation.
     */
    public function handleReturn(Request $request, Invoice $invoice): PaymentReturnResult;

    /**
     * Verify and process an asynchronous server-to-server webhook notification for the given
     * invoice (already resolved from the webhook route itself, so this never has to guess which
     * tenant's credentials to verify against). Returns the Payment it affected, or null if the
     * event wasn't one this app cares about.
     */
    public function handleWebhook(Request $request, Invoice $invoice): ?Payment;

    /**
     * Refund a previously completed payment (fully, or partially if $amount is given) and record
     * the refund as its own ledger entry (Payment with type=refund).
     */
    public function refund(Payment $payment, ?int $amount = null): Payment;
}
