<?php

namespace App\Models;

use App\Enums\PricingUnit;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booking's own snapshot of one inclusion/exclusion line. `type` is its state on this booking;
 * `default_included` is what the package said (null for custom or extra catalog items), which is
 * how BookingPricing works out whether the line adds to or refunds the price.
 */
class BookingIncludeExclude extends Model
{
    use BelongsToTenant;

    protected $table = 'booking_include_exclude';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'include_exclude_id',
        'type',
        'title',
        'description',
        'unit_price',
        'pricing_unit',
        'units',
        'line_total',
        'default_included',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'pricing_unit' => PricingUnit::class,
            'units' => 'integer',
            'line_total' => 'integer',
            'default_included' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function includeExclude(): BelongsTo
    {
        return $this->belongsTo(IncludeExclude::class);
    }

    /**
     * The package's inclusion/exclusion list as booking rows (money in cents), ready for
     * syncRows(). Picking a package snapshots these; editing the package later doesn't change
     * existing bookings.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rowsFromPackage(Package $package): array
    {
        return $package->includeExcludeItems()->with('includeExclude')->get()
            ->filter(fn (PackageIncludeExclude $item): bool => $item->includeExclude !== null)
            ->values()
            ->map(fn (PackageIncludeExclude $item, int $index): array => [
                'include_exclude_id' => $item->include_exclude_id,
                'type' => $item->is_included ? 'include' : 'exclude',
                'title' => $item->includeExclude->title,
                'description' => $item->includeExclude->description,
                'unit_price' => $item->unit_price,
                'pricing_unit' => $item->includeExclude->pricing_unit?->value ?? PricingUnit::PerPerson->value,
                'default_included' => $item->is_included,
                'sort_order' => $index,
            ])->all();
    }

    /**
     * A catalog item not on the package, added to one booking: it has no package default, so it
     * is charged when included.
     *
     * @return array<string, mixed>
     */
    public static function rowFromCatalog(IncludeExclude $item, string $type = 'include'): array
    {
        return [
            'include_exclude_id' => $item->getKey(),
            'type' => $type,
            'title' => $item->title,
            'description' => $item->description,
            'unit_price' => $item->unit_price,
            'pricing_unit' => $item->pricing_unit?->value ?? PricingUnit::PerPerson->value,
            'default_included' => null,
        ];
    }

    /**
     * Replaces a booking's inclusion/exclusion rows with the given set (money in cents). Units and
     * line totals are left for BookingPricing::recalculate() to fill in.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function syncRows(Booking $booking, array $rows): void
    {
        $booking->includeExcludes()->delete();

        foreach (array_values($rows) as $index => $row) {
            if (blank($row['title'] ?? null)) {
                continue;
            }

            $defaultIncluded = $row['default_included'] ?? null;

            $booking->includeExcludes()->create([
                'include_exclude_id' => $row['include_exclude_id'] ?? null,
                'type' => ($row['type'] ?? 'include') === 'exclude' ? 'exclude' : 'include',
                'title' => $row['title'],
                'description' => $row['description'] ?? null,
                'unit_price' => max(0, (int) ($row['unit_price'] ?? 0)),
                'pricing_unit' => PricingUnit::tryFrom((string) ($row['pricing_unit'] ?? ''))?->value ?? PricingUnit::PerPerson->value,
                'default_included' => $defaultIncluded === null || $defaultIncluded === '' ? null : (bool) $defaultIncluded,
                'sort_order' => $index,
            ]);
        }
    }
}
