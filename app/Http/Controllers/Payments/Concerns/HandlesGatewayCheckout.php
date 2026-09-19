<?php

namespace App\Http\Controllers\Payments\Concerns;

use App\Models\Invoice;
use App\Services\PaymentGateways\PaymentGatewayResolver;
use Filament\Notifications\Notification;
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

    /**
     * Flashes both a plain session key (read directly by the public, non-Filament payment page —
     * see resources/views/payments/public-show.blade.php) and a Filament notification (picked up
     * automatically by the portal panel on its next render, since Notification::send() just pushes
     * onto the session under its own key) — one call covers both destinations this trait serves.
     */
    private function handleGatewayReturn(Request $request, Invoice $invoice, string $gateway, string $backTo): RedirectResponse
    {
        $result = app(PaymentGatewayResolver::class)->for($gateway)->handleReturn($request, $invoice);

        [$sessionKey, $message, $status] = match ($result->status) {
            'completed' => ['payment_success', 'Payment received — thank you!', 'success'],
            'pending' => ['payment_pending', $result->message ?? "We're confirming your payment now.", 'info'],
            'cancelled' => ['payment_error', $result->message ?? 'Payment was cancelled.', 'warning'],
            default => ['payment_error', $result->message ?? 'Payment could not be completed.', 'danger'],
        };

        Notification::make()->title($message)->status($status)->send();

        return redirect()->to($backTo)->with($sessionKey, $message);
    }
}
