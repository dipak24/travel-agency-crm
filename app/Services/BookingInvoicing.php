<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;

/**
 * Turns a booking's price breakdown into a draft invoice, so the invoice always matches the
 * booking total. Charges (base price, added items, add-ons, surcharges) become invoice lines;
 * reductions (group discount, removed inclusions, negative adjustments) go into the invoice's
 * discount.
 */
class BookingInvoicing
{
    public function createDraft(Booking $booking, ?TenantUser $issuedBy = null): Invoice
    {
        $quote = app(BookingPricing::class)->recalculate($booking);

        return DB::transaction(function () use ($booking, $quote, $issuedBy): Invoice {
            $charges = array_values(array_filter($quote->lines, fn (array $line): bool => $line['amount'] > 0));
            $amount = array_sum(array_column($charges, 'amount'));

            $invoice = Invoice::query()->create([
                'booking_id' => $booking->getKey(),
                'customer_id' => $booking->customer_id,
                'amount' => $amount,
                'tax' => 0,
                'discount' => max(0, $amount - $quote->total),
                'total' => $quote->total,
                'currency' => $booking->tenant?->currency ?? 'USD',
                'status' => 'draft',
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

            return $invoice;
        });
    }
}
