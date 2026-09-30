<?php

namespace Database\Seeders;

use App\Enums\PackageCategory;
use App\Enums\PricingUnit;
use App\Models\FixedDeparture;
use App\Models\GroupDiscountTier;
use App\Models\IncludeExclude;
use App\Models\Package;
use App\Models\PackageIncludeExclude;
use App\Models\PackageService;
use App\Models\PromoCode;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * The demo agency's catalog: published, draft and archived packages with priced inclusions and
 * exclusions, fixed departures, add-on services, a group discount and a promo code. Safe to run
 * repeatedly. Runs without model events (DatabaseSeeder), so tenant_id is
 * set explicitly.
 */
class DemoCatalogSeeder extends Seeder
{
    /**
     * @var array<string, mixed>
     */
    private array $scoped = [];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-travel')->first();

        if ($tenant === null) {
            return;
        }

        $this->scoped = ['tenant_id' => $tenant->id];

        $items = $this->seedIncludeExcludes();
        $services = $this->seedServices();

        $everest = $this->seedPackage([
            'slug' => 'everest-base-camp-trek',
            'package_code' => 'EBC-14',
            'name' => 'Everest Base Camp Trek',
            'category' => PackageCategory::Trek,
            'description' => 'The classic trek to the foot of Everest via Namche Bazaar and Tengboche.',
            'duration_days' => 14,
            'base_price' => 95000,
            'sales_price' => 140000,
            'status' => 'published',
            'itinerary' => '<h2>Day 1: Arrive in Kathmandu</h2><p>Airport pick-up and transfer to the hotel.</p>'
                .'<h2>Day 2: Fly to Lukla, trek to Phakding</h2><p>Scenic mountain flight and a short first trek.</p>'
                .'<h2>Day 3: Trek to Namche Bazaar</h2><p>Climb to the Sherpa capital.</p>'
                .'<h2>Days 4–14</h2><ul><li>Acclimatisation in Namche</li><li>Tengboche monastery</li><li>Everest Base Camp and Kala Patthar</li><li>Return to Lukla and fly back</li></ul>',
        ], $items, ['Hotel in Kathmandu', 'Licensed trekking guide', 'Kathmandu–Lukla return flights', 'Sagarmatha National Park permit'], ['Porter (one per two trekkers)', 'Travel insurance', 'Nepal visa fee']);

        $annapurna = $this->seedPackage([
            'slug' => 'annapurna-base-camp-trek',
            'package_code' => 'ABC-10',
            'name' => 'Annapurna Base Camp Trek',
            'category' => PackageCategory::Trek,
            'description' => 'Through Gurung villages and rhododendron forest into the Annapurna Sanctuary.',
            'duration_days' => 10,
            'base_price' => 60000,
            'sales_price' => 89000,
            'status' => 'published',
            'itinerary' => '<h2>Day 1: Kathmandu to Pokhara</h2><p>Tourist bus or flight to the lakeside.</p>'
                .'<h2>Day 2: Drive to Nayapul, trek to Ghandruk</h2><p>First views of Annapurna South.</p>'
                .'<h2>Days 3–10</h2><p>Chhomrong, Machapuchare Base Camp, Annapurna Base Camp and back to Pokhara.</p>',
        ], $items, ['Hotel in Kathmandu', 'Licensed trekking guide', 'Annapurna Conservation Area permit'], ['Porter (one per two trekkers)', 'Travel insurance']);

        $this->seedPackage([
            'slug' => 'chitwan-jungle-safari',
            'package_code' => 'CHT-3',
            'name' => 'Chitwan Jungle Safari',
            'category' => PackageCategory::Tour,
            'description' => 'Jeep safari, canoe ride and a Tharu village walk in Chitwan National Park.',
            'duration_days' => 3,
            'base_price' => 18000,
            'sales_price' => 29000,
            'status' => 'draft',
            'itinerary' => '<p><strong>Day 1:</strong> Drive to Sauraha, sunset by the Rapti river.</p><p><strong>Day 2:</strong> Jeep safari and canoe ride.</p><p><strong>Day 3:</strong> Bird watching and return.</p>',
        ], $items, ['Hotel in Kathmandu'], ['Travel insurance']);

        $this->seedPackage([
            'slug' => 'langtang-valley-trek',
            'package_code' => 'LNG-7',
            'name' => 'Langtang Valley Trek',
            'category' => PackageCategory::Trek,
            'description' => 'A short trek north of Kathmandu, retired from sale this season.',
            'duration_days' => 7,
            'base_price' => 40000,
            'sales_price' => 62000,
            'status' => 'archived',
            'itinerary' => '<p>Syabrubesi – Lama Hotel – Langtang village – Kyanjin Gompa and back.</p>',
        ], $items, ['Licensed trekking guide'], ['Porter (one per two trekkers)']);

        $this->seedDepartures($everest, [2, 4], 14, 12);
        $this->seedDepartures($annapurna, [1, 3], 10, 10);

        PackageService::query()->withoutGlobalScopes()->updateOrCreate(
            [...$this->scoped, 'package_id' => $everest->id, 'service_id' => $services['skydiving']->id],
            ['price' => 42000, 'is_featured' => true],
        );
        PackageService::query()->withoutGlobalScopes()->updateOrCreate(
            [...$this->scoped, 'package_id' => $annapurna->id, 'service_id' => $services['paragliding']->id],
            ['price' => $services['paragliding']->price, 'is_featured' => true],
        );

        GroupDiscountTier::query()->withoutGlobalScopes()->updateOrCreate(
            [...$this->scoped, 'package_id' => $everest->id, 'min_pax' => 4],
            ['max_pax' => null, 'discount_type' => 'percentage', 'discount_value' => 10],
        );
        GroupDiscountTier::query()->withoutGlobalScopes()->updateOrCreate(
            [...$this->scoped, 'package_id' => null, 'min_pax' => 6],
            ['max_pax' => null, 'discount_type' => 'percentage', 'discount_value' => 5],
        );

        $promo = PromoCode::query()->withoutGlobalScopes()->updateOrCreate(
            [...$this->scoped, 'code' => 'HIMALAYA10'],
            ['discount_type' => 'percent', 'discount_value' => 10, 'usage_limit' => 50, 'valid_from' => now()->startOfDay(), 'valid_until' => now()->addMonths(6), 'is_active' => true],
        );
        $promo->packages()->syncWithoutDetaching([$everest->id, $annapurna->id]);
    }

    /**
     * @return array<string, IncludeExclude>
     */
    private function seedIncludeExcludes(): array
    {
        $catalog = [
            ['Hotel in Kathmandu', 'include', 4500, PricingUnit::PerPersonPerDay],
            ['Licensed trekking guide', 'include', 3000, PricingUnit::PerDay],
            ['Kathmandu–Lukla return flights', 'include', 42000, PricingUnit::PerPerson],
            ['Sagarmatha National Park permit', 'include', 3000, PricingUnit::PerPerson],
            ['Annapurna Conservation Area permit', 'include', 3000, PricingUnit::PerPerson],
            ['Porter (one per two trekkers)', 'exclude', 2500, PricingUnit::PerDay],
            ['Travel insurance', 'exclude', 9000, PricingUnit::PerPerson],
            ['Nepal visa fee', 'exclude', 5000, PricingUnit::PerPerson],
        ];

        $items = [];

        foreach ($catalog as $index => [$title, $type, $price, $unit]) {
            $items[$title] = IncludeExclude::query()->withoutGlobalScopes()->updateOrCreate(
                [...$this->scoped, 'title' => $title],
                ['type' => $type, 'unit_price' => $price, 'pricing_unit' => $unit, 'sort_order' => $index],
            );
        }

        return $items;
    }

    /**
     * @return array<string, Service>
     */
    private function seedServices(): array
    {
        $services = [
            'skydiving' => ['Tandem skydiving in Pokhara', 'Activity', 45000, PricingUnit::PerPerson],
            'paragliding' => ['Paragliding over Phewa Lake', 'Activity', 9000, PricingUnit::PerPerson],
            'airport' => ['Private airport transfer', 'Transport', 3500, PricingUnit::PerTrip],
            'helicopter' => ['Everest helicopter return', 'Transport', 120000, PricingUnit::PerPerson],
        ];

        return collect($services)->map(fn (array $service): Service => Service::query()->withoutGlobalScopes()->updateOrCreate(
            [...$this->scoped, 'name' => $service[0]],
            ['category' => $service[1], 'price' => $service[2], 'currency' => 'USD', 'pricing_unit' => $service[3], 'is_active' => true],
        ))->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, IncludeExclude>  $items
     * @param  array<int, string>  $included
     * @param  array<int, string>  $excluded
     */
    private function seedPackage(array $attributes, array $items, array $included, array $excluded): Package
    {
        $package = Package::query()->withoutGlobalScopes()->updateOrCreate(
            [...$this->scoped, 'package_code' => $attributes['package_code']],
            $attributes,
        );

        $rows = [...array_fill_keys($included, true), ...array_fill_keys($excluded, false)];

        foreach (array_keys($rows) as $index => $title) {
            PackageIncludeExclude::query()->withoutGlobalScopes()->updateOrCreate(
                [...$this->scoped, 'package_id' => $package->id, 'include_exclude_id' => $items[$title]->id],
                ['is_included' => $rows[$title], 'unit_price' => $items[$title]->unit_price, 'sort_order' => $index],
            );
        }

        return $package;
    }

    /**
     * @param  array<int, int>  $monthsAhead
     */
    private function seedDepartures(Package $package, array $monthsAhead, int $days, int $slots): void
    {
        foreach ($monthsAhead as $months) {
            $start = now()->addMonths($months)->startOfMonth()->addDays(9);

            $exists = FixedDeparture::query()->withoutGlobalScopes()
                ->where('package_id', $package->id)
                ->whereDate('start_date', $start)
                ->exists();

            if (! $exists) {
                FixedDeparture::query()->withoutGlobalScopes()->create([
                    ...$this->scoped,
                    'package_id' => $package->id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
                    'total_slots' => $slots,
                    'overbooking_buffer' => 2,
                    'status' => 'open',
                ]);
            }
        }
    }
}
