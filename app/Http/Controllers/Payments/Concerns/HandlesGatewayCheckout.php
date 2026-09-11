<?php

namespace App\Http\Controllers\Payments\Concerns;

use App\Models\Invoice;
use App\Services\PaymentGateways\PaymentGatewayResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shared by the portal's (auth:customer) checkout controller and the public, signed-link checkout
 * controller — both start/confirm a gateway payment identically; only how the invoice is
 * authorized, and where the browser lands afterwards, differs between them.
 */
trait HandlesGatewayCheckout
{
    private function startGatewayCheckout(Invoice $invoice, string $gateway, string $backTo, string $returnUrl): RedirectResponse
    {
        $resolver = app(PaymentGatewayResolver::class);
        $amount = $invoice->balanceDue();

        if ($amount <= 0) {
            return redirect()->to($backTo)->with('payment_error', 'This invoice has no outstanding balance.');
        }

        $gatewayInstance = $resolver->for($gateway);

        if (! $gatewayInstance->isEnabledFor($invoice)) {
            return redirect()->to($backTo)->with('payment_error', 'That payment method is not available for this invoice.');
        }

        try {
            $url = $gatewayInstance->startCheckout($invoice, $amount, $returnUrl);
        } catch (RuntimeException $e) {
            Log::error('Payment checkout failed to start.', ['gateway' => $gateway, 'invoice_id' => $invoice->id, 'message' => $e->getMessage()]);

            return redirect()->to($backTo)->with('payment_error', 'Unable to start payment: '.$e->getMessage());
        }

        return redirect()->away($url);
    }

    private function handleGatewayReturn(Request $request, Invoice $invoice, string $gateway, string $backTo): RedirectResponse
    {
        $result = app(PaymentGatewayResolver::class)->for($gateway)->handleReturn($request, $invoice);

        return match ($result->status) {
            'completed' => redirect()->to($backTo)->with('payment_success', 'Payment received — thank you!'),
            'pending' => redirect()->to($backTo)->with('payment_pending', $result->message ?? "We're confirming your payment now."),
            'cancelled' => redirect()->to($backTo)->with('payment_error', $result->message ?? 'Payment was cancelled.'),
            default => redirect()->to($backTo)->with('payment_error', $result->message ?? 'Payment could not be completed.'),
        };
    }
}
