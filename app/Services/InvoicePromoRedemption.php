<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PromoCode;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvoicePromoRedemption
{
    /**
     * Promo codes always stack (per the architecture doc's discount
     * resolution order) and are recorded as a zero-amount reference line
     * item on the invoice — `invoice_items.total` is an unsigned column, so
     * it cannot carry a negative "discount" row directly. The actual
     * monetary effect is applied to `invoices.discount`/`invoices.total`,
     * which already exist for exactly this purpose. The reference line item
     * doubles as the guard against applying the same code twice to the same
     * invoice.
     */
    public function apply(Invoice $invoice, string $code): PromoCode
    {
        $code = strtoupper(trim($code));

        $promo = PromoCode::query()->where('code', $code)->first();

        if (! $promo || ! $promo->is_active) {
            throw new LogicException('That promo code is not valid.');
        }

        $now = now();

        if ($promo->valid_from && $now->lt($promo->valid_from)) {
            throw new LogicException('That promo code is not active yet.');
        }

        if ($promo->valid_until && $now->gt($promo->valid_until)) {
            throw new LogicException('That promo code has expired.');
        }

        if ($promo->usage_limit !== null && $promo->used_count >= $promo->usage_limit) {
            throw new LogicException('That promo code has reached its usage limit.');
        }

        $restrictedPackageIds = $promo->packages()->pluck('packages.id');

        if ($restrictedPackageIds->isNotEmpty()) {
            $packageId = $invoice->booking?->package_id;

            if ($packageId === null || ! $restrictedPackageIds->contains($packageId)) {
                throw new LogicException('That promo code does not apply to this booking\'s package.');
            }
        }

        $lineDescription = "Promo code: {$code}";

        if ($invoice->items()->where('description', $lineDescription)->exists()) {
            throw new LogicException('That promo code has already been applied to this invoice.');
        }

        $discount = $promo->discount_type === 'percent'
            ? (int) round($invoice->total * $promo->discount_value / 100)
            : min($promo->discount_value, $invoice->total);

        if ($discount <= 0) {
            throw new LogicException('This invoice has no balance left to discount.');
        }

        DB::transaction(function () use ($invoice, $promo, $discount, $lineDescription): void {
            $invoice->items()->create([
                'description' => $lineDescription,
                'qty' => 1,
                'unit_price' => 0,
                'total' => 0,
            ]);

            $invoice->update([
                'discount' => $invoice->discount + $discount,
                'total' => max(0, $invoice->total - $discount),
            ]);

            $promo->increment('used_count');

            $invoice->recalculateStatus();
        });

        return $promo;
    }
}
