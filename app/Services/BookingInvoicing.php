<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\TenantUser;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Turns a booking's price breakdown into invoices, so what is billed always adds up to the
 * booking total. Charges (base price, added items, add-ons, surcharges) become invoice lines;
 * reductions (group discount, removed inclusions, negative adjustments) and anything already
 * invoiced for the booking (e.g. an online deposit) go into the invoice's discount.
 */
class BookingInvoicing
{
    /**
     * A draft invoice for whatever of the booking total hasn't been invoiced yet.
     */
    public function createDraft(Booking $booking, ?TenantUser $issuedBy = null, string $status = 'draft'): Invoice
    {
        $quote = app(BookingPricing::class)->recalculate($booking);
        $alreadyInvoiced = $this->alreadyInvoiced($booking);

        return DB::transaction(function () use ($booking, $quote, $issuedBy, $status, $alreadyInvoiced): Invoice {
            $charges = array_values(array_filter($quote->lines, fn (array $line): bool => $line['amount'] > 0));
            $amount = array_sum(array_column($charges, 'amount'));
            $total = max(0, $quote->total - $alreadyInvoiced);

            $invoice = Invoice::query()->create([
                'booking_id' => $booking->getKey(),
                'customer_id' => $booking->customer_id,
                'amount' => $amount,
                'tax' => 0,
                'discount' => max(0, $amount - $total),
                'total' => $total,
                'currency' => $booking->tenant?->currency ?? 'USD',
                'status' => $status,
                'due_date' => $booking->start_date,
                'issued_by' => $issuedBy?->getKey(),
            ]);

            foreach ($charges as $line) {
                $invoice->items()->create([
                    'description' => $line['label'].(filled($line['detail']) ? " ({$line['detail']})" : ''),
                    'qty' => 1,
                    'unit_price' => $line['amount'],
                    'total' => $line['amount'],
                ]);
            }

            if ($alreadyInvoiced > 0) {
                $invoice->items()->create([
                    'description' => 'Less: already invoiced for this booking ('.Money::format($alreadyInvoiced, $invoice->currency).')',
                    'qty' => 1,
                    'unit_price' => 0,
                    'total' => 0,
                ]);
            }

            return $invoice;
        });
    }

    /**
     * The deposit a traveller pays to secure an online booking: an issued invoice for the given
     * share of the booking total, due now. With 100% it is the full invoice instead.
     */
    public function createDeposit(Booking $booking, int $percent): Invoice
    {
        if ($percent >= 100) {
            return $this->createDraft($booking, status: 'issued');
        }

        $quote = app(BookingPricing::class)->recalculate($booking);
        $deposit = (int) round($quote->total * max(1, $percent) / 100);

        return DB::transaction(function () use ($booking, $deposit, $percent, $quote): Invoice {
            $currency = $booking->tenant?->currency ?? 'USD';

            $invoice = Invoice::query()->create([
                'booking_id' => $booking->getKey(),
                'customer_id' => $booking->customer_id,
                'amount' => $deposit,
                'tax' => 0,
                'discount' => 0,
                'total' => $deposit,
                'currency' => $currency,
                'status' => 'issued',
                'due_date' => today(),
            ]);

            $invoice->items()->create([
                'description' => "Deposit ({$percent}%) to confirm {$booking->trip_name} — trip total ".Money::format($quote->total, $currency),
                'qty' => 1,
                'unit_price' => $deposit,
                'total' => $deposit,
            ]);

            return $invoice;
        });
    }

    private function alreadyInvoiced(Booking $booking): int
    {
        return (int) $booking->invoices()->where('status', '!=', 'cancelled')->sum('total');
    }
}
