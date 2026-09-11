<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GiftVoucher;
use App\Models\Invoice;
use App\Notifications\GiftVoucherPurchased;
use Illuminate\Support\Str;
use LogicException;

/**
 * A gift voucher is bought by the customer, not manually issued by tenant staff — this service
 * turns "I want to buy a $50 voucher" into a normal invoice, reusing the exact same
 * PayPal/HBL checkout flow every other invoice uses. Once that invoice is fully paid,
 * `fulfill()` (called from `Invoice`'s own `updated` hook — see Invoice::booted()) mints the
 * actual voucher and emails the code to the buyer.
 */
class GiftVoucherPurchase
{
    public function createInvoice(Customer $customer, int $amount, string $currency): Invoice
    {
        if ($amount < 1) {
            throw new LogicException('Enter an amount greater than zero.');
        }

        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'booking_id' => null,
            'amount' => $amount,
            'total' => $amount,
            'currency' => $currency,
            'status' => 'issued',
            'purpose' => 'gift_voucher_purchase',
        ]);

        $invoice->items()->create([
            'description' => 'Gift voucher purchase',
            'qty' => 1,
            'unit_price' => $amount,
            'total' => $amount,
        ]);

        return $invoice;
    }

    public function fulfill(Invoice $invoice): void
    {
        if ($invoice->purpose !== 'gift_voucher_purchase') {
            return;
        }

        if (GiftVoucher::query()->where('source_invoice_id', $invoice->id)->exists()) {
            return;
        }

        $voucher = GiftVoucher::query()->create([
            'code' => $this->generateUniqueCode(),
            'value' => $invoice->total,
            'currency' => $invoice->currency,
            'issued_to' => $invoice->customer_id,
            'source_invoice_id' => $invoice->id,
            'status' => 'unredeemed',
        ]);

        $invoice->customer?->notify(new GiftVoucherPurchased($voucher));
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'GV-'.strtoupper(Str::random(8));
        } while (GiftVoucher::query()->where('code', $code)->exists());

        return $code;
    }
}
