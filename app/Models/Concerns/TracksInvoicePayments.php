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
