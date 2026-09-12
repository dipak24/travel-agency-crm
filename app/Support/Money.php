<?php

namespace App\Support;

/**
 * Every money column in this app is stored as an integer in minor units (cents) — Invoice.total,
 * Payment.amount, Booking.total_amount, etc. Staff and customers should never type or read raw
 * cents in any UI, so every Filament form/table/infolist money field converts through here (see
 * App\Filament\Forms\Components\MoneyInput for the form-input side, and Filament's own built-in
 * TextColumn/TextEntry ->money($currency, divideBy: 100) for the display side).
 */
class Money
{
    /**
     * Minor units (cents) as stored in the database -> the major-unit decimal (dollars) shown to
     * and entered by a user.
     */
    public static function toDecimal(int|string|null $cents): ?float
    {
        if ($cents === null || $cents === '') {
            return null;
        }

        return round(((int) $cents) / 100, 2);
    }

    /**
     * The major-unit decimal (dollars) a user typed -> the minor-unit integer (cents) the database
     * stores.
     */
    public static function toCents(float|int|string|null $decimal): ?int
    {
        if ($decimal === null || $decimal === '') {
            return null;
        }

        return (int) round(((float) $decimal) * 100);
    }

    /**
     * Format a cents value as a "12.34"-style string (optionally "CUR 12.34"), for the places that
     * can't use Filament's own ->money() column/entry formatting — Placeholder content, PDFs,
     * notifications.
     */
    public static function format(int|string|null $cents, string $currency = ''): string
    {
        $decimal = number_format(self::toDecimal($cents) ?? 0, 2);

        return $currency !== '' ? "{$currency} {$decimal}" : $decimal;
    }
}
