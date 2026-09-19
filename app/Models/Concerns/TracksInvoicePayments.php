<?php

namespace App\Models\Concerns;

trait TracksInvoicePayments
{
    /**
     * Sum of completed payments, with completed refunds subtracted back out.
     */
    public function paidAmount(): int
    {
        $completed = $this->payments()->where('status', 'completed');

        $received = (int) (clone $completed)->where('type', '!=', 'refund')->sum('amount');
        $refunded = (int) (clone $completed)->where('type', 'refund')->sum('amount');

        return $received - $refunded;
    }

    /**
     * Remaining amount owed. Can go negative to signal an overpayment/credit.
     */
    public function balanceDue(): int
    {
        return $this->total - $this->paidAmount();
    }

    /**
     * How much of paidAmount() came from redeeming a gift voucher — shown as its own line in the
     * "Order summary" breakdown. A redemption is a Payment (method=gift_voucher), not a change to
     * `discount`/`total`, so this has to be summed from the ledger rather than read off a column.
     */
    public function giftVoucherAppliedAmount(): int
    {
        $completed = $this->payments()->where('method', 'gift_voucher')->where('status', 'completed');

        $received = (int) (clone $completed)->where('type', '!=', 'refund')->sum('amount');
        $refunded = (int) (clone $completed)->where('type', 'refund')->sum('amount');

        return $received - $refunded;
    }

    /**
     * The payment method shown as an invoice's "Mode" in list views — the most recent completed
     * payment's method, or null if nothing has been paid yet.
     */
    public function latestPaymentMethod(): ?string
    {
        return $this->payments()
            ->where('status', 'completed')
            ->latest('paid_at')
            ->value('method');
    }

    /**
     * Recompute status from the payment ledger. Never touches draft/cancelled.
     */
    public function recalculateStatus(): void
    {
        if (! in_array($this->status, ['issued', 'partially_paid', 'overdue', 'paid'], true)) {
            return;
        }

        $paid = $this->paidAmount();

        $status = match (true) {
            $paid <= 0 => 'issued',
            $this->total > 0 && $paid >= $this->total => 'paid',
            default => 'partially_paid',
        };

        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }
}
