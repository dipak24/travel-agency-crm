<?php

namespace App\Filament\Tenant\Resources\BookingResource;

use App\Enums\BookingType;
use App\Enums\PricingUnit;
use App\Models\Booking;
use App\Models\BookingIncludeExclude;
use App\Models\Service;
use App\Services\BookingPricing;
use App\Support\BookingQuote;
use App\Support\Money;

/**
 * Translates between BookingResource's form state and the booking's stored rows. The two
 * inclusion/exclusion repeaters aren't relationship-bound, so their money is kept in decimal
 * dollars in the form (like every MoneyInput) and converted to cents only here.
 *
 * Each row carries `selected` (is it part of this trip?) and `default_included` (what the package
 * said), which is all BookingPricing needs to show whether a change adds to or refunds the price.
 */
class BookingFormState
{
    public const INCLUSIONS = 'inclusion_rows';

    public const EXCLUSIONS = 'exclusion_rows';

    public static function currency(): string
    {
        return auth('tenant')->user()?->tenant?->currency ?? 'USD';
    }

    /**
     * A Select bound to an enum can hold either the enum or its raw value.
     */
    public static function pricingUnit(mixed $state): PricingUnit
    {
        return $state instanceof PricingUnit ? $state : (PricingUnit::tryFrom((string) $state) ?? PricingUnit::PerPerson);
    }

    public static function bookingType(mixed $state): BookingType
    {
        return $state instanceof BookingType ? $state : (BookingType::tryFrom((string) $state) ?? BookingType::Individual);
    }

    /**
     * Stored rows (cents) → the two repeaters' state (dollars). Items the package includes by
     * default stay in "Inclusions" even when unticked, so CST sees what was removed; default
     * exclusions stay in "Exclusions" even when added. Extra items sit where their current state
     * puts them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{inclusion_rows: array<string, array<string, mixed>>, exclusion_rows: array<string, array<string, mixed>>}
     */
    public static function rowsToState(array $rows): array
    {
        $state = [self::INCLUSIONS => [], self::EXCLUSIONS => []];

        foreach (array_values($rows) as $index => $row) {
            $pricingUnit = $row['pricing_unit'] ?? PricingUnit::PerPerson->value;
            $defaultIncluded = $row['default_included'] ?? null;
            $selected = ($row['type'] ?? 'include') === 'include';
            $section = match (true) {
                $defaultIncluded === true => self::INCLUSIONS,
                $defaultIncluded === false => self::EXCLUSIONS,
                default => $selected ? self::INCLUSIONS : self::EXCLUSIONS,
            };

            $state[$section]["row-{$index}"] = [
                'include_exclude_id' => $row['include_exclude_id'] ?? null,
                'title' => $row['title'] ?? '',
                'description' => $row['description'] ?? null,
                'unit_price' => Money::toDecimal((int) ($row['unit_price'] ?? 0)),
                'pricing_unit' => $pricingUnit instanceof PricingUnit ? $pricingUnit->value : $pricingUnit,
                'default_included' => $defaultIncluded,
                'is_custom' => (bool) ($row['is_custom'] ?? false),
                'selected' => $selected,
            ];
        }

        return $state;
    }

    /**
     * @return array{inclusion_rows: array<string, array<string, mixed>>, exclusion_rows: array<string, array<string, mixed>>}
     */
    public static function stateForBooking(?Booking $booking): array
    {
        if ($booking === null) {
            return [self::INCLUSIONS => [], self::EXCLUSIONS => []];
        }

        return self::rowsToState($booking->includeExcludes()->orderBy('sort_order')->get()
            ->map(fn (BookingIncludeExclude $row): array => [
                ...$row->only(['include_exclude_id', 'type', 'title', 'description', 'unit_price', 'default_included', 'is_custom']),
                'pricing_unit' => $row->pricing_unit?->value,
            ])->all());
    }

    /**
     * The two repeaters' state (dollars) → rows ready for BookingIncludeExclude::syncRows() (cents).
     *
     * @param  array<array-key, array<string, mixed>>|null  $inclusionRows
     * @param  array<array-key, array<string, mixed>>|null  $exclusionRows
     * @return array<int, array<string, mixed>>
     */
    public static function stateToRows(?array $inclusionRows, ?array $exclusionRows): array
    {
        $rows = [];

        foreach ([...array_values($inclusionRows ?? []), ...array_values($exclusionRows ?? [])] as $row) {
            if (blank($row['title'] ?? null)) {
                continue;
            }

            $defaultIncluded = $row['default_included'] ?? null;

            $rows[] = [
                'include_exclude_id' => filled($row['include_exclude_id'] ?? null) ? (int) $row['include_exclude_id'] : null,
                'type' => filter_var($row['selected'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'include' : 'exclude',
                'title' => $row['title'],
                'description' => $row['description'] ?? null,
                'unit_price' => max(0, Money::toCents($row['unit_price'] ?? 0) ?? 0),
                'pricing_unit' => self::pricingUnit($row['pricing_unit'] ?? null)->value,
                'default_included' => $defaultIncluded === null || $defaultIncluded === '' ? null : filter_var($defaultIncluded, FILTER_VALIDATE_BOOLEAN),
                'is_custom' => filter_var($row['is_custom'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $rows;
    }

    /**
     * A live quote from the whole form's raw state (money in dollars), for the price summary.
     *
     * @param  array<string, mixed>  $state
     */
    public static function quote(array $state): BookingQuote
    {
        $rows = self::stateToRows($state[self::INCLUSIONS] ?? [], $state[self::EXCLUSIONS] ?? []);

        $addonRows = array_values($state['addons'] ?? []);
        $serviceNames = Service::query()
            ->whereIn('id', array_filter(array_column($addonRows, 'service_id')))
            ->pluck('name', 'id');

        $addons = array_map(fn (array $addon): array => [
            'label' => ($serviceNames[$addon['service_id'] ?? 0] ?? 'Service').' × '.max(1, (int) ($addon['quantity'] ?? 1)),
            'price' => Money::toCents($addon['price'] ?? 0) ?? 0,
            'status' => (string) ($addon['status'] ?? 'requested'),
        ], $addonRows);

        return app(BookingPricing::class)->calculate(
            bookingType: self::bookingType($state['booking_type'] ?? null),
            packageId: filled($state['package_id'] ?? null) ? (int) $state['package_id'] : null,
            pax: (int) ($state['pax_count'] ?? 1),
            days: (int) ($state['duration_days'] ?? 1),
            perPersonPrice: Money::toCents($state['per_person_price'] ?? 0) ?? 0,
            items: $rows,
            addons: $addons,
            manualAdjustment: Money::toCents($state['manual_adjustment'] ?? 0) ?? 0,
            manualAdjustmentReason: $state['manual_adjustment_reason'] ?? null,
        );
    }

    /**
     * The effect of one inclusion/exclusion row on the price, as a signed amount in cents.
     *
     * @param  array<string, mixed>  $row
     */
    public static function rowDelta(array $row, int $pax, int $days): int
    {
        $unit = self::pricingUnit($row['pricing_unit'] ?? null);
        $lineTotal = max(0, Money::toCents($row['unit_price'] ?? 0) ?? 0) * $unit->units($pax, $days);
        $defaultIncluded = $row['default_included'] ?? null;

        return BookingPricing::itemDelta(
            filter_var($row['selected'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'include' : 'exclude',
            $defaultIncluded === null || $defaultIncluded === '' ? null : filter_var($defaultIncluded, FILTER_VALIDATE_BOOLEAN),
            $lineTotal,
        );
    }
}
