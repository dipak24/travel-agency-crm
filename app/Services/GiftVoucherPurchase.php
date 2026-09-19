<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GiftVoucher;
use App\Models\Invoice;
use App\Notifications\GiftVoucherPurchased;
use App\Services\Mail\TenantMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use LogicException;

/**
 * A gift voucher is bought by the customer, not manually issued by tenant staff — this service
 * turns "I want to buy a $50 voucher for these 3 people" into a normal invoice (one line item per
 * recipient, reusing the exact same PayPal/HBL checkout flow every other invoice uses) and, once
 * that invoice is fully paid, mints one voucher per recipient with its own code (see `fulfill()`,
 * called from `Invoice`'s own `updated` hook — see `Invoice::booted()`).
 */
class GiftVoucherPurchase
{
    /**
     * @param  array{first_name: string, last_name: string, email: string, phone: string}  $purchaser
     * @param  array<int, array{first_name: string, last_name: string, email: string, phone: string}>  $recipients
     */
    public function createInvoice(Customer $customer, array $purchaser, int $amountPerVoucher, string $currency, array $recipients): Invoice
    {
        if ($amountPerVoucher < 1) {
            throw new LogicException('Enter an amount greater than zero.');
        }

        if ($recipients === []) {
            throw new LogicException('Add at least one recipient.');
        }

        $emails = collect($recipients)->map(fn (array $r): string => mb_strtolower(trim($r['email'])));

        if ($emails->duplicates()->isNotEmpty()) {
            throw new LogicException('Each recipient must have a unique email address.');
        }

        $phones = collect($recipients)->map(fn (array $r): string => trim($r['phone']))->filter();

        if ($phones->duplicates()->isNotEmpty()) {
            throw new LogicException('Each recipient must have a unique mobile number.');
        }

        return DB::transaction(function () use ($customer, $purchaser, $amountPerVoucher, $currency, $recipients): Invoice {
            $total = $amountPerVoucher * count($recipients);

            $invoice = Invoice::query()->create([
                'customer_id' => $customer->id,
                'booking_id' => null,
                'amount' => $total,
                'total' => $total,
                'currency' => $currency,
                'status' => 'issued',
                'purpose' => 'gift_voucher_purchase',
                'purchaser_first_name' => $purchaser['first_name'],
                'purchaser_last_name' => $purchaser['last_name'],
                'purchaser_email' => $purchaser['email'],
                'purchaser_phone' => $purchaser['phone'],
            ]);

            foreach ($recipients as $recipient) {
                $name = trim("{$recipient['first_name']} {$recipient['last_name']}");

                $invoice->items()->create([
                    'description' => "Gift voucher purchase — for {$name}",
                    'qty' => 1,
                    'unit_price' => $amountPerVoucher,
                    'total' => $amountPerVoucher,
                    'recipient_first_name' => $recipient['first_name'],
                    'recipient_last_name' => $recipient['last_name'],
                    'recipient_email' => $recipient['email'],
                    'recipient_phone' => $recipient['phone'],
                ]);
            }

            return $invoice;
        });
    }

    public function fulfill(Invoice $invoice): void
    {
        if ($invoice->purpose !== 'gift_voucher_purchase') {
            return;
        }

        $items = $invoice->items()->whereNotNull('recipient_email')->get();

        foreach ($items as $item) {
            if (GiftVoucher::query()->where('source_invoice_item_id', $item->id)->exists()) {
                continue;
            }

            $voucher = GiftVoucher::query()->create([
                'code' => $this->generateUniqueCode(),
                'value' => $item->total,
                'currency' => $invoice->currency,
                'issued_to' => $this->matchRecipientCustomer($invoice->tenant_id, $item->recipient_email),
                'source_invoice_id' => $invoice->id,
                'source_invoice_item_id' => $item->id,
                'recipient_first_name' => $item->recipient_first_name,
                'recipient_last_name' => $item->recipient_last_name,
                'recipient_email' => $item->recipient_email,
                'recipient_phone' => $item->recipient_phone,
                'status' => 'unredeemed',
            ]);

            app(TenantMailer::class)->send(
                $invoice->tenant_id,
                Notification::route('mail', $item->recipient_email),
                new GiftVoucherPurchased($voucher, $item->recipientName() ?? 'there'),
            );
        }
    }

    private function matchRecipientCustomer(int $tenantId, string $email): ?int
    {
        return Customer::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->value('id');
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'GV-'.strtoupper(Str::random(8));
        } while (GiftVoucher::query()->where('code', $code)->exists());

        return $code;
    }
}
