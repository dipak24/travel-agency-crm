<?php

namespace Database\Seeders;

use App\Enums\DocumentType;
use App\Enums\PackageCategory;
use App\Enums\PricingUnit;
use App\Models\FixedDeparture;
use App\Models\GroupDiscountTier;
use App\Models\IncludeExclude;
use App\Models\Package;
use App\Models\PackageIncludeExclude;
use App\Models\PackageService;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * A ready-to-book Everest Base Camp trek for the demo agency: priced inclusions and exclusions,
 * two fixed departures, a skydiving add-on and a group discount. Safe to run repeatedly.
 * Runs without model events (DatabaseSeeder), so tenant_id is set explicitly.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-travel')->first();

        if ($tenant === null) {
            return;
        }

        $scoped = ['tenant_id' => $tenant->id];

        $package = Package::query()->withoutGlobalScopes()->updateOrCreate(
            [...$scoped, 'slug' => 'everest-base-camp-trek'],
            [
                'name' => 'Everest Base Camp Trek',
                'package_code' => 'EBC-14',
                'category' => PackageCategory::Trek,
                'description' => 'The classic trek to the foot of Everest via Namche Bazaar and Tengboche.',
                'duration_days' => 14,
                'base_price' => 95000,
                'sales_price' => 140000,
                'min_pax' => 1,
                'max_pax' => 16,
                'is_public' => true,
                'status' => 'published',
                'document_requirements' => [
                    DocumentType::Passport->value => true,
                    DocumentType::PpPhoto->value => true,
                    DocumentType::Visa->value => false,
                    DocumentType::Insurance->value => true,
                    DocumentType::Other->value => false,
                ],
                'itinerary' => [
                    ['title' => 'Arrive in Kathmandu', 'description' => 'Airport pick-up and transfer to the hotel.'],
                    ['title' => 'Fly to Lukla, trek to Phakding', 'description' => 'Scenic mountain flight and a short first trek.'],
                    ['title' => 'Trek to Namche Bazaar', 'description' => 'Climb to the Sherpa capital.'],
                ],
            ],
        );

        $items = [
            ['Hotel in Kathmandu', 'include', 4500, PricingUnit::PerPersonPerDay, true],
            ['Licensed trekking guide', 'include', 3000, PricingUnit::PerDay, true],
            ['Kathmandu–Lukla return flights', 'include', 42000, PricingUnit::PerPerson, true],
            ['Sagarmatha National Park permit', 'include', 3000, PricingUnit::PerPerson, true],
            ['Porter (one per two trekkers)', 'exclude', 2500, PricingUnit::PerDay, false],
            ['Travel insurance', 'exclude', 9000, PricingUnit::PerPerson, false],
            ['Nepal visa fee', 'exclude', 5000, PricingUnit::PerPerson, false],
        ];

        foreach ($items as $index => [$title, $type, $price, $unit, $included]) {
            $item = IncludeExclude::query()->withoutGlobalScopes()->updateOrCreate(
                [...$scoped, 'title' => $title],
                ['type' => $type, 'unit_price' => $price, 'pricing_unit' => $unit, 'sort_order' => $index],
            );

            PackageIncludeExclude::query()->withoutGlobalScopes()->updateOrCreate(
                [...$scoped, 'package_id' => $package->id, 'include_exclude_id' => $item->id],
                ['is_included' => $included, 'sort_order' => $index],
            );
        }

        foreach ([now()->addMonths(2), now()->addMonths(4)] as $start) {
            $start = $start->startOfMonth()->addDays(9);

            $exists = FixedDeparture::query()->withoutGlobalScopes()
                ->where('package_id', $package->id)
                ->whereDate('start_date', $start)
                ->exists();

            if (! $exists) {
                FixedDeparture::query()->withoutGlobalScopes()->create([
                    ...$scoped,
                    'package_id' => $package->id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->addDays(13)->toDateString(),
                    'total_slots' => 12,
                    'overbooking_buffer' => 2,
                    'status' => 'open',
                ]);
            }
        }

        $skydiving = Service::query()->withoutGlobalScopes()->updateOrCreate(
            [...$scoped, 'name' => 'Tandem skydiving in Pokhara'],
            ['category' => 'Activity', 'price' => 45000, 'currency' => 'USD', 'pricing_unit' => PricingUnit::PerPerson, 'is_active' => true],
        );

        PackageService::query()->withoutGlobalScopes()->updateOrCreate(
            [...$scoped, 'package_id' => $package->id, 'service_id' => $skydiving->id],
            ['price_override' => 42000, 'is_featured' => true],
        );

        GroupDiscountTier::query()->withoutGlobalScopes()->updateOrCreate(
            [...$scoped, 'package_id' => $package->id, 'min_pax' => 4],
            ['max_pax' => null, 'discount_type' => 'percentage', 'discount_value' => 10],
        );
    }
}
