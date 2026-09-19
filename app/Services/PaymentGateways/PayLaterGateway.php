<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentGateways\Concerns\ResolvesTenantCredentials;
use App\Support\PaymentReturnResult;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Not a real payment processor — a tenant-toggleable option (no credentials, see
 * ResolvesTenantCredentials::settingsFor()) that lets a customer defer payment instead of paying
 * online now. Selecting it records nothing: the invoice is simply left at its current balance,
 * to be settled later either through a real gateway or a payment tenant staff enter manually.
 * `startCheckout()` sends the browser straight back through the normal return round-trip (no
 * external hop exists to make), and `handleReturn()` is where the "saved for later" message
 * actually comes from.
 */
class PayLaterGateway implements PaymentGateway
{
    use ResolvesTenantCredentials;

    public function key(): string
    {
        return 'pay_later';
    }

    public function label(): string
    {
        return 'Pay Later';
    }

    public function isEnabledFor(Invoice $invoice): bool
    {
        return (bool) $this->settingsFor($invoice)?->enabled;
    }

    public function startCheckout(Invoice $invoice, int $amount, string $returnUrl): string
    {
        return $returnUrl;
    }

    public function handleReturn(Request $request, Invoice $invoice): PaymentReturnResult
    {
        return new PaymentReturnResult(
            'pending',
            message: 'This invoice is saved to pay later — you can settle it any time from your invoice page.',
        );
    }

    public function handleWebhook(Request $request, Invoice $invoice): ?Payment
    {
        return null;
    }

    public function refund(Payment $payment, ?int $amount = null): Payment
    {
        throw new RuntimeException('Pay Later has no completed payments to refund.');
    }
}
