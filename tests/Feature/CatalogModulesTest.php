<?php

use App\Models\FixedDeparture;
use App\Models\GroupDiscountTier;
use App\Models\IncludeExclude;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceAvailability;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;

uses(RefreshDatabase::class);

function catalogTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

test('all catalog modules are isolated between tenants', function () {
    $firstTenant = catalogTenant('First Agency', 'first-agency');
    $secondTenant = catalogTenant('Second Agency', 'second-agency');

    app(TenantContext::class)->set($firstTenant);
    $package = Package::query()->create([
        'name' => 'First Package',
        'slug' => 'first-package',
        'package_code' => 'FIRST-01',
        'duration_days' => 5,
    ]);
    $records = [
        IncludeExclude::query()->create(['type' => 'include', 'title' => 'Airport transfer']),
        FixedDeparture::query()->create([
            'package_id' => $package->id,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05',
            'total_slots' => 10,
        ]),
        GroupDiscountTier::query()->create([
            'package_id' => $package->id,
            'min_pax' => 5,
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]),
        Service::query()->create(['name' => 'City tour', 'price' => 2500]),
    ];
    $availability = ServiceAvailability::query()->create([
        'service_id' => $records[3]->id,
        'date' => '2026-10-02',
        'total_slots' => 15,
    ]);

    app(TenantContext::class)->set($secondTenant);

    foreach (array_merge($records, [$availability]) as $record) {
        expect($record::query()->find($record->id))->toBeNull();
    }

    expect(ServiceAvailability::query()->withoutGlobalScopes()->find($availability->id)->tenant_id)
        ->toBe($firstTenant->id);
});

test('service availability reports remaining slots and cannot be created without tenant context', function () {
    $tenant = catalogTenant('Travel Agency', 'travel-agency');
    app(TenantContext::class)->set($tenant);
    $service = Service::query()->create(['name' => 'Equipment rental', 'price' => 1000]);
    $availability = ServiceAvailability::query()->create([
        'service_id' => $service->id,
        'date' => '2026-11-01',
        'total_slots' => 3,
        'booked_slots' => 2,
    ]);

    expect($availability->remainingSlots())->toBe(1);

    app(TenantContext::class)->clear();

    expect(fn () => Service::query()->create(['name' => 'No tenant', 'price' => 1]))
        ->toThrow(LogicException::class);
});

test('tenant-owned catalog relations reject cross-tenant writes', function () {
    $firstTenant = catalogTenant('First Agency', 'first-agency');
    $secondTenant = catalogTenant('Second Agency', 'second-agency');

    app(TenantContext::class)->set($firstTenant);
    $package = Package::query()->create([
        'name' => 'First Package',
        'slug' => 'first-package',
        'package_code' => 'FIRST-01',
        'duration_days' => 3,
    ]);
    $service = Service::query()->create(['name' => 'First Service', 'price' => 100]);

    app(TenantContext::class)->set($secondTenant);

    expect(fn () => FixedDeparture::query()->create([
        'package_id' => $package->id,
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-03',
        'total_slots' => 5,
    ]))->toThrow(LogicException::class);

    expect(fn () => ServiceAvailability::query()->create([
        'service_id' => $service->id,
        'date' => '2026-12-01',
        'total_slots' => 5,
    ]))->toThrow(LogicException::class);

    expect(fn () => GroupDiscountTier::query()->create([
        'package_id' => $package->id,
        'min_pax' => 2,
        'discount_type' => 'percentage',
        'discount_value' => 5,
    ]))->toThrow(LogicException::class);
});
