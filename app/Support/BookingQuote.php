<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The priced breakdown of one booking, produced by App\Services\BookingPricing. All amounts are in
 * cents. `lines` is the ordered, human-readable breakdown shown to CST and customers.
 */
final readonly class BookingQuote
{
    /**
     * @param  array<int, array{kind: string, label: string, detail: ?string, amount: int}>  $lines
     */
    public function __construct(
        public int $perPersonPrice,
        public int $pax,
        public int $days,
        public int $baseAmount,
        public ?int $groupDiscountTierId,
        public int $groupDiscountAmount,
        public int $inclusionsAdjustment,
        public int $addonsAmount,
        public int $manualAdjustment,
        public int $total,
        public array $lines,
    ) {}

    /**
     * The breakdown as a small table: one row per line, reductions in green, then the total.
     */
    public function toHtml(string $currency): HtmlString
    {
        $rows = collect($this->lines)->map(fn (array $line): string => sprintf(
            '<tr><td class="py-1 pe-4">%s%s</td><td class="py-1 text-end tabular-nums %s">%s%s</td></tr>',
            e($line['label']),
            filled($line['detail']) ? '<div class="text-xs text-gray-500">'.e($line['detail']).'</div>' : '',
            $line['amount'] < 0 ? 'text-success-600' : '',
            $line['amount'] < 0 ? '− ' : '',
            e(Money::format(abs($line['amount']), $currency)),
        ))->implode('');

        return new HtmlString(
            '<table class="w-full text-sm">'.$rows
            .'<tr class="border-t font-semibold"><td class="pt-2">Total</td><td class="pt-2 text-end tabular-nums">'
            .e(Money::format($this->total, $currency)).'</td></tr></table>'
        );
    }

    /**
     * The booking columns this quote writes.
     *
     * @return array<string, int|null>
     */
    public function toBookingAttributes(): array
    {
        return [
            'per_person_price' => $this->perPersonPrice,
            'base_amount' => $this->baseAmount,
            'group_discount_tier_id' => $this->groupDiscountTierId,
            'group_discount_amount' => $this->groupDiscountAmount,
            'inclusions_adjustment' => $this->inclusionsAdjustment,
            'addons_amount' => $this->addonsAmount,
            'manual_adjustment' => $this->manualAdjustment,
            'total_amount' => $this->total,
        ];
    }
}
