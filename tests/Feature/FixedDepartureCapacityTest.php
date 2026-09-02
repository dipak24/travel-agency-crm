<?php

use App\Models\FixedDeparture;
use App\Models\Package;
use App\Models\Tenant;
use App\Services\FixedDepartureCapacity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('departure capacity includes the configured overbooking buffer', function () {
    $tenant = Tenant::query()->create(['name' => 'Travel Agency', 'slug' => 'travel-agency']);
    app(TenantContext::class)->set($tenant);

    $package = Package::query()->create([
        'name' => 'Mountain Escape',
        'slug' => 'mountain-escape',
        'package_code' => 'ME-01',
        'duration_days' => 5,
    ]);
    $departure = FixedDeparture::query()->create([
        'package_id' => $package->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-05',
        'total_slots' => 2,
        'overbooking_buffer' => 1,
    ]);

    $service = app(FixedDepartureCapacity::class);
    $reserved = $service->reserve($departure, 2);

    expect($reserved->booked_slots)->toBe(2)
        ->and($reserved->status)->toBe('full')
        ->and($reserved->remainingSlots())->toBe(1);

    $reserved = $service->reserve($reserved, 1);

    expect($reserved->booked_slots)->toBe(3)
        ->and($reserved->remainingSlots())->toBe(0);
});

test('capacity service rejects reservations beyond the buffer', function () {
    $tenant = Tenant::query()->create(['name' => 'Travel Agency', 'slug' => 'travel-agency']);
    app(TenantContext::class)->set($tenant);

    $package = Package::query()->create([
        'name' => 'City Break',
        'slug' => 'city-break',
        'package_code' => 'CB-01',
        'duration_days' => 3,
    ]);
    $departure = FixedDeparture::query()->create([
        'package_id' => $package->id,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-03',
        'total_slots' => 1,
    ]);

    expect(fn () => app(FixedDepartureCapacity::class)->reserve($departure, 2))
        ->toThrow(LogicException::class);

    expect(FixedDeparture::query()->findOrFail($departure->id)->booked_slots)->toBe(0);
});
