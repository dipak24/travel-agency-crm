<?php

namespace App\Services;

use App\Enums\BookingType;
use App\Enums\DiscountType;
use App\Enums\PricingUnit;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\BookingIncludeExclude;
use App\Models\FixedDeparture;
use App\Models\GroupDiscountTier;
use App\Models\Package;
use App\Support\BookingQuote;
use App\Support\Money;

/**
 * The single source of truth for a booking's price. Every total is built from traceable lines:
 *
 *   per-person price × pax
 *   − group discount (fixed and private groups only; best matching tier, package-specific first)
 *   ± inclusion/exclusion changes against the package defaults
 *   + approved add-ons
 *   ± manual adjustment
 *
 * The total never goes below zero. Promo codes and gift vouchers apply later, on the invoice.
 */
class BookingPricing
{
    /**
     * The starting per-person price for a booking: the departure's override, else the package's
     * price, else zero (a custom trip priced by hand).
     */
    public function defaultPerPersonPrice(?Package $package, ?FixedDeparture $departure = null): int
    {
        return $departure?->price_override ?? $package?->sales_price ?? 0;
    }

    /**
     * Price a booking from raw values (all money in cents).
     *
     * @param  array<int, array{title: string, type: string, default_included: ?bool, unit_price: int, pricing_unit: PricingUnit|string|null}>  $items
     * @param  array<int, array{label: string, price: int, status: string}>  $addons
     */
    public function calculate(
        BookingType|string|null $bookingType,
        ?int $packageId,
        int $pax,
        int $days,
        int $perPersonPrice,
        array $items = [],
        array $addons = [],
        int $manualAdjustment = 0,
        ?string $manualAdjustmentReason = null,
    ): BookingQuote {
        $bookingType = $bookingType instanceof BookingType ? $bookingType : (BookingType::tryFrom((string) $bookingType) ?? BookingType::Individual);
        $pax = max(1, $pax);
        $days = max(1, $days);
        $perPersonPrice = max(0, $perPersonPrice);

        $lines = [];

        $baseAmount = $perPersonPrice * $pax;
        $lines[] = [
            'kind' => 'base',
            'label' => 'Package price',
            'detail' => Money::format($perPersonPrice).' × '.$pax.' '.($pax === 1 ? 'person' : 'people'),
            'amount' => $baseAmount,
        ];

        [$tier, $groupDiscountAmount] = $bookingType->earnsGroupDiscount()
            ? $this->groupDiscount($packageId, $pax, $baseAmount)
            : [null, 0];

        if ($tier !== null && $groupDiscountAmount > 0) {
            $lines[] = [
                'kind' => 'discount',
                'label' => 'Group discount',
                'detail' => $this->describeTier($tier),
                'amount' => -$groupDiscountAmount,
            ];
        }

        $inclusionsAdjustment = 0;

        foreach ($items as $item) {
            $unit = $this->pricingUnit($item['pricing_unit'] ?? null);
            $units = $unit->units($pax, $days);
            $lineTotal = max(0, (int) $item['unit_price']) * $units;
            $delta = self::itemDelta($item['type'], $item['default_included'] ?? null, $lineTotal);

            if ($delta === 0) {
                continue;
            }

            $inclusionsAdjustment += $delta;
            $lines[] = [
                'kind' => 'inclusion',
                'label' => ($delta > 0 ? 'Added: ' : 'Removed: ').$item['title'],
                'detail' => Money::format($item['unit_price']).' × '.$units.' '.$unit->shortLabel(),
                'amount' => $delta,
            ];
        }

        $addonsAmount = 0;

        foreach ($addons as $addon) {
            if (! in_array($addon['status'], BookingAddon::BILLABLE_STATUSES, true)) {
                continue;
            }

            $price = max(0, (int) $addon['price']);
            $addonsAmount += $price;
            $lines[] = [
                'kind' => 'addon',
                'label' => 'Add-on: '.$addon['label'],
                'detail' => null,
                'amount' => $price,
            ];
        }

        if ($manualAdjustment !== 0) {
            $lines[] = [
                'kind' => 'manual',
                'label' => 'Adjustment',
                'detail' => $manualAdjustmentReason,
                'amount' => $manualAdjustment,
            ];
        }

        $total = max(0, $baseAmount - $groupDiscountAmount + $inclusionsAdjustment + $addonsAmount + $manualAdjustment);

        return new BookingQuote(
            perPersonPrice: $perPersonPrice,
            pax: $pax,
            days: $days,
            baseAmount: $baseAmount,
            groupDiscountTierId: $groupDiscountAmount > 0 ? $tier?->getKey() : null,
            groupDiscountAmount: $groupDiscountAmount,
            inclusionsAdjustment: $inclusionsAdjustment,
            addonsAmount: $addonsAmount,
            manualAdjustment: $manualAdjustment,
            total: $total,
            lines: $lines,
        );
    }

    /**
     * Re-price a saved booking from its own rows, refreshing each inclusion row's units and line
     * total, and writing every amount column plus total_amount.
     */
    public function recalculate(Booking $booking): BookingQuote
    {
        $quote = $this->quoteFor($booking, persistLineTotals: true);

        $booking->forceFill($quote->toBookingAttributes());

        if ($booking->isDirty()) {
            $booking->save();
        }

        return $quote;
    }

    /**
     * Price a saved booking from its own rows without changing the booking — e.g. to show the
     * breakdown in the customer portal.
     */
    public function quoteFor(Booking $booking, bool $persistLineTotals = false): BookingQuote
    {
        $booking->loadMissing('package');
        $pax = max(1, (int) $booking->pax_count);
        $days = $this->daysFor($booking);

        $items = [];

        foreach ($booking->includeExcludes()->orderBy('sort_order')->get() as $row) {
            /** @var BookingIncludeExclude $row */
            $unit = $this->pricingUnit($row->pricing_unit);
            $units = $unit->units($pax, $days);
            $row->forceFill(['units' => $units, 'line_total' => $row->unit_price * $units]);

            if ($persistLineTotals && $row->isDirty()) {
                $row->save();
            }

            $items[] = [
                'title' => $row->title,
                'type' => $row->type,
                'default_included' => $row->default_included,
                'unit_price' => $row->unit_price,
                'pricing_unit' => $unit,
            ];
        }

        $addons = $booking->addons()->with('service')->get()->map(fn (BookingAddon $addon): array => [
            'label' => $addon->label().' × '.$addon->quantity,
            'price' => $addon->price,
            'status' => $addon->status,
        ])->all();

        return $this->calculate(
            bookingType: $booking->booking_type,
            packageId: $booking->package_id,
            pax: $pax,
            days: $days,
            perPersonPrice: (int) $booking->per_person_price,
            items: $items,
            addons: $addons,
            manualAdjustment: (int) $booking->manual_adjustment,
            manualAdjustmentReason: $booking->manual_adjustment_reason,
        );
    }

    /**
     * How an inclusion/exclusion row moves the price against the package defaults: dropping a
     * default inclusion refunds it, adding a default exclusion (or any custom/extra item) charges
     * it, and anything left as the package intends is already in the base price.
     */
    public static function itemDelta(string $type, ?bool $defaultIncluded, int $lineTotal): int
    {
        $included = $type === 'include';

        if ($included && $defaultIncluded !== true) {
            return $lineTotal;
        }

        if (! $included && $defaultIncluded === true) {
            return -$lineTotal;
        }

        return 0;
    }

    public function daysFor(Booking $booking): int
    {
        if ($booking->duration_days) {
            return $booking->duration_days;
        }

        if ($booking->start_date && $booking->end_date) {
            return (int) $booking->start_date->diffInDays($booking->end_date) + 1;
        }

        return max(1, (int) ($booking->package?->duration_days ?? 1));
    }

    /**
     * The best group discount tier for this many travellers: a tier set on the package beats a
     * global one, and among those the one with the highest minimum pax wins.
     *
     * @return array{0: ?GroupDiscountTier, 1: int}
     */
    public function groupDiscount(?int $packageId, int $pax, int $baseAmount): array
    {
        $tier = GroupDiscountTier::query()
            ->where(fn ($query) => $query->whereNull('package_id')->when($packageId, fn ($q) => $q->orWhere('package_id', $packageId)))
            ->where('min_pax', '<=', $pax)
            ->where(fn ($query) => $query->whereNull('max_pax')->orWhere('max_pax', '>=', $pax))
            ->orderByRaw('case when package_id is null then 1 else 0 end')
            ->orderByDesc('min_pax')
            ->first();

        if ($tier === null) {
            return [null, 0];
        }

        $amount = $tier->discount_type === DiscountType::Percentage->value
            ? (int) round($baseAmount * $tier->discount_value / 100)
            : $tier->discount_value * $pax;

        return [$tier, min($amount, $baseAmount)];
    }

    private function describeTier(GroupDiscountTier $tier): string
    {
        $range = $tier->max_pax ? "{$tier->min_pax}–{$tier->max_pax} pax" : "{$tier->min_pax}+ pax";

        $value = $tier->discount_type === DiscountType::Percentage->value
            ? "{$tier->discount_value}%"
            : Money::format($tier->discount_value).' per person';

        return "{$range}, {$value}";
    }

    private function pricingUnit(PricingUnit|string|null $unit): PricingUnit
    {
        if ($unit instanceof PricingUnit) {
            return $unit;
        }

        return PricingUnit::tryFrom((string) $unit) ?? PricingUnit::PerPerson;
    }
}
