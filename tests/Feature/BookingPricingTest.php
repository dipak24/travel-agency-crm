<?php

use App\Enums\BookingType;
use App\Enums\PricingUnit;
use App\Models\Booking;
use App\Models\BookingIncludeExclude;
use App\Models\GroupDiscountTier;
use App\Models\IncludeExclude;
use App\Models\Package;
use App\Models\PackageIncludeExclude;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\BookingPricing;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::query()->create(['name' => 'Himalayan Trails', 'slug' => 'himalayan-trails']);
    app(TenantContext::class)->set($tenant);
    $this->pricing = app(BookingPricing::class);
});

test('the base price is the per-person price times pax', function () {
    $quote = $this->pricing->calculate(BookingType::Individual, null, pax: 3, days: 5, perPersonPrice: 100000);

    expect($quote->baseAmount)->toBe(300000)
        ->and($quote->total)->toBe(300000)
        ->and($quote->lines[0]['kind'])->toBe('base');
});

test('the package-specific group discount tier beats a global one, and individuals get none', function () {
    $package = Package::factory()->create();
    GroupDiscountTier::query()->create(['package_id' => null, 'min_pax' => 4, 'discount_type' => 'percentage', 'discount_value' => 5]);
    $packageTier = GroupDiscountTier::query()->create(['package_id' => $package->id, 'min_pax' => 4, 'max_pax' => 8, 'discount_type' => 'percentage', 'discount_value' => 10]);

    $group = $this->pricing->calculate(BookingType::FixedGroup, $package->id, pax: 4, days: 12, perPersonPrice: 100000);
    $individual = $this->pricing->calculate(BookingType::Individual, $package->id, pax: 4, days: 12, perPersonPrice: 100000);

    expect($group->groupDiscountTierId)->toBe($packageTier->id)
        ->and($group->groupDiscountAmount)->toBe(40000)
        ->and($group->total)->toBe(360000)
        ->and($individual->groupDiscountAmount)->toBe(0)
        ->and($individual->total)->toBe(400000);
});

test('a fixed tier takes its amount off per person, and no tier applies below its minimum pax', function () {
    GroupDiscountTier::query()->create(['min_pax' => 3, 'discount_type' => 'fixed', 'discount_value' => 5000]);

    expect($this->pricing->calculate(BookingType::PrivateGroup, null, 3, 5, 100000)->groupDiscountAmount)->toBe(15000)
        ->and($this->pricing->calculate(BookingType::PrivateGroup, null, 2, 5, 100000)->groupDiscountAmount)->toBe(0);
});

test('every pricing unit scales with pax and days', function (PricingUnit $unit, int $expectedUnits) {
    expect($unit->units(pax: 3, days: 4))->toBe($expectedUnits);
})->with([
    [PricingUnit::PerTrip, 1],
    [PricingUnit::PerDay, 4],
    [PricingUnit::PerPerson, 3],
    [PricingUnit::PerPersonPerDay, 12],
]);

test('removing a default inclusion refunds it, adding a default exclusion or custom item charges it', function () {
    $items = [
        ['title' => 'Hotel in Kathmandu', 'type' => 'exclude', 'default_included' => true, 'unit_price' => 4500, 'pricing_unit' => 'per_person_per_day'],
        ['title' => 'Porter', 'type' => 'include', 'default_included' => false, 'unit_price' => 2500, 'pricing_unit' => 'per_day'],
        ['title' => 'Farewell dinner', 'type' => 'include', 'default_included' => null, 'unit_price' => 3000, 'pricing_unit' => 'per_person'],
        ['title' => 'Guide', 'type' => 'include', 'default_included' => true, 'unit_price' => 2500, 'pricing_unit' => 'per_day'],
        ['title' => 'Travel insurance', 'type' => 'exclude', 'default_included' => null, 'unit_price' => 9000, 'pricing_unit' => 'per_person'],
    ];

    $quote = $this->pricing->calculate(BookingType::Individual, null, pax: 2, days: 2, perPersonPrice: 100000, items: $items);

    // −45×2×2 = −180; +25×2 = +50; +30×2 = +60; guide and insurance unchanged.
    expect($quote->inclusionsAdjustment)->toBe(-18000 + 5000 + 6000)
        ->and($quote->total)->toBe(200000 - 7000)
        ->and(collect($quote->lines)->where('kind', 'inclusion')->pluck('label')->all())
        ->toBe(['Removed: Hotel in Kathmandu', 'Added: Porter', 'Added: Farewell dinner']);
});

test('only approved or booked add-ons count, and a manual adjustment never takes the total below zero', function () {
    $addons = [
        ['label' => 'Skydiving', 'price' => 20000, 'status' => 'approved'],
        ['label' => 'Rafting', 'price' => 8000, 'status' => 'requested'],
        ['label' => 'Flight', 'price' => 15000, 'status' => 'booked'],
        ['label' => 'Spa', 'price' => 5000, 'status' => 'cancelled'],
    ];

    $quote = $this->pricing->calculate(BookingType::Individual, null, 1, 1, 10000, addons: $addons, manualAdjustment: -2000, manualAdjustmentReason: 'Loyalty');

    expect($quote->addonsAmount)->toBe(35000)
        ->and($quote->total)->toBe(10000 + 35000 - 2000);

    expect($this->pricing->calculate(BookingType::Individual, null, 1, 1, 10000, manualAdjustment: -50000)->total)->toBe(0);
});

test('recalculating a saved booking writes every amount column and the inclusion line totals', function () {
    $package = Package::factory()->create(['sales_price' => 100000, 'duration_days' => 3]);
    $hotel = IncludeExclude::factory()->create(['title' => 'Hotel', 'unit_price' => 4500, 'pricing_unit' => PricingUnit::PerPersonPerDay]);
    PackageIncludeExclude::query()->create(['package_id' => $package->id, 'include_exclude_id' => $hotel->id, 'is_included' => true]);
    $booking = Booking::factory()->create([
        'package_id' => $package->id, 'booking_type' => BookingType::PrivateGroup,
        'pax_count' => 2, 'duration_days' => 3, 'per_person_price' => 100000,
    ]);
    BookingIncludeExclude::syncRows($booking, array_map(fn (array $row): array => [...$row, 'type' => 'exclude'], BookingIncludeExclude::rowsFromPackage($package)));
    $booking->addons()->create(['service_id' => Service::factory()->create()->id, 'unit_price' => 15000, 'price' => 15000, 'quantity' => 1, 'status' => 'approved', 'added_by' => 'staff']);

    $this->pricing->recalculate($booking);
    $booking->refresh();

    expect($booking->base_amount)->toBe(200000)
        ->and($booking->inclusions_adjustment)->toBe(-27000)
        ->and($booking->addons_amount)->toBe(15000)
        ->and($booking->total_amount)->toBe(188000)
        ->and($booking->includeExcludes()->first()->line_total)->toBe(27000);
});
