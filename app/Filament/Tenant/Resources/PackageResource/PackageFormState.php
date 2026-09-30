<?php

namespace App\Filament\Tenant\Resources\PackageResource;

use App\Models\IncludeExclude;
use App\Models\Package;
use App\Models\PackageIncludeExclude;
use App\Models\PackageService;
use App\Models\Service;
use App\Support\Money;

/**
 * The package form's two picking lists: the whole Include / Exclude catalog and every service,
 * each row ticked if it belongs to this package, with the package's own price. Titles, units and
 * descriptions always come from the catalog; only the pick, included/excluded and price are the
 * package's. Form state holds money in dollars; the database in cents.
 */
class PackageFormState
{
    public const INCLUSIONS = 'catalog_items';

    public const SERVICES = 'service_items';

    /**
     * Every catalog item as a row, with this package's choices filled in where it has the item.
     *
     * @return array<string, array{include_exclude_id: int, title: string, pricing_unit: ?string, selected: bool, placement: string, unit_price: ?float}>
     */
    public static function inclusionState(?Package $package): array
    {
        $picked = $package?->includeExcludeItems()->get()->keyBy('include_exclude_id') ?? collect();

        return IncludeExclude::query()->orderBy('sort_order')->orderBy('title')->get()
            ->mapWithKeys(function (IncludeExclude $item) use ($picked): array {
                /** @var ?PackageIncludeExclude $link */
                $link = $picked->get($item->id);

                return ["item-{$item->id}" => [
                    'include_exclude_id' => $item->id,
                    'title' => $item->title,
                    'pricing_unit' => $item->pricing_unit?->getLabel(),
                    'selected' => $link !== null,
                    'placement' => $link !== null ? ($link->is_included ? 'include' : 'exclude') : $item->type,
                    'unit_price' => Money::toDecimal($link?->unit_price ?? $item->unit_price),
                ]];
            })
            ->all();
    }

    /**
     * Every active service (plus any inactive one the package still offers) as a row.
     *
     * @return array<string, array{service_id: int, name: string, category: ?string, pricing_unit: ?string, selected: bool, unit_price: ?float, is_featured: bool}>
     */
    public static function serviceState(?Package $package): array
    {
        $picked = $package?->serviceLinks()->get()->keyBy('service_id') ?? collect();

        return Service::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $picked->keys()))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (Service $service) use ($picked): array {
                /** @var ?PackageService $link */
                $link = $picked->get($service->id);

                return ["service-{$service->id}" => [
                    'service_id' => $service->id,
                    'name' => $service->name,
                    'category' => $service->category,
                    'pricing_unit' => $service->pricing_unit?->getLabel(),
                    'selected' => $link !== null,
                    'unit_price' => Money::toDecimal($link?->price ?? $service->price),
                    'is_featured' => (bool) $link?->is_featured,
                ]];
            })
            ->all();
    }

    /**
     * Saves the ticked rows as the package's inclusions/exclusions and add-on services and removes
     * the unticked ones. Rows naming an item or service outside this agency are ignored.
     *
     * @param  array<array-key, array<string, mixed>>  $inclusionRows
     * @param  array<array-key, array<string, mixed>>  $serviceRows
     */
    public static function sync(Package $package, array $inclusionRows, array $serviceRows): void
    {
        $catalogIds = IncludeExclude::query()->pluck('id')->all();
        $keptItems = [];

        foreach (array_values($inclusionRows) as $index => $row) {
            $itemId = (int) ($row['include_exclude_id'] ?? 0);

            if (! self::isTicked($row) || ! in_array($itemId, $catalogIds, true)) {
                continue;
            }

            $keptItems[] = $itemId;
            $package->includeExcludeItems()->updateOrCreate(['include_exclude_id' => $itemId], [
                'is_included' => ($row['placement'] ?? 'include') !== 'exclude',
                'unit_price' => max(0, Money::toCents($row['unit_price'] ?? 0) ?? 0),
                'sort_order' => $index,
            ]);
        }

        $package->includeExcludeItems()->whereNotIn('include_exclude_id', $keptItems)->get()->each->delete();

        $serviceIds = Service::query()->pluck('id')->all();
        $keptServices = [];

        foreach ($serviceRows as $row) {
            $serviceId = (int) ($row['service_id'] ?? 0);

            if (! self::isTicked($row) || ! in_array($serviceId, $serviceIds, true)) {
                continue;
            }

            $keptServices[] = $serviceId;
            $package->serviceLinks()->updateOrCreate(['service_id' => $serviceId], [
                'price' => max(0, Money::toCents($row['unit_price'] ?? 0) ?? 0),
                'is_featured' => filter_var($row['is_featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $package->serviceLinks()->whereNotIn('service_id', $keptServices)->get()->each->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function isTicked(array $row): bool
    {
        return filter_var($row['selected'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
