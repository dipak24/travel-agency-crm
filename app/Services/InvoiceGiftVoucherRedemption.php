<?php

namespace App\Services;

use App\Models\GiftVoucher;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvoiceGiftVoucherRedemption
{
    /**
     * A gift voucher is applied as payment credit, not a discount (per the
     * architecture doc's discount resolution order) — so redeeming one just
     * creates a completed Payment, which the Payment model's own `saved`
     * hook already folds into the invoice's status via recalculateStatus().
     * `value` is treated as the voucher's *remaining* balance: it decreases
     * on each partial redemption rather than a separate ledger table.
     */
    public function redeem(Invoice $invoice, string $code): Payment
    {
        $code = strtoupper(trim($code));

        $voucher = GiftVoucher::query()->where('code', $code)->first();

        if (! $voucher || ! in_array($voucher->status, ['unredeemed', 'partially_redeemed'], true)) {
            throw new LogicException('That gift voucher is not valid or has already been fully redeemed.');
        }

        if ($voucher->expires_at && now()->gt($voucher->expires_at)) {
            throw new LogicException('That gift voucher has expired.');
        }

        if ($voucher->issued_to !== null && $voucher->issued_to !== $invoice->customer_id) {
            throw new LogicException('That gift voucher was issued to a different customer.');
        }

        $transactionRef = "GV-{$voucher->code}-{$invoice->id}";

        if (Payment::query()->where('transaction_ref', $transactionRef)->exists()) {
            throw new LogicException('That gift voucher has already been redeemed on this invoice.');
        }

        $amount = min($voucher->value, $invoice->balanceDue());

        if ($amount <= 0) {
            throw new LogicException('This invoice has no outstanding balance to apply the voucher to.');
        }

        return DB::transaction(function () use ($invoice, $voucher, $amount, $transactionRef): Payment {
            $payment = $invoice->payments()->create([
                'amount' => $amount,
                // Fall back to the schema default: an Invoice created via
                // `create([...])` without an explicit `currency` keeps a
                // stale null in memory for any column the DB default filled
                // in, until the model is refreshed.
                'currency' => $invoice->currency ?? 'USD',
                'method' => 'gift_voucher',
                'type' => 'installment',
                'status' => 'completed',
                'transaction_ref' => $transactionRef,
                'paid_at' => now(),
            ]);

            $remaining = $voucher->value - $amount;

            $voucher->update([
                'value' => $remaining,
                'status' => $remaining > 0 ? 'partially_redeemed' : 'redeemed',
            ]);

            return $payment;
        });
    }
}
