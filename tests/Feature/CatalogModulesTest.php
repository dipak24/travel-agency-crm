<?php

use App\Models\FixedDeparture;
use App\Models\GiftVoucher;
use App\Models\GroupDiscountTier;
use App\Models\IncludeExclude;
use App\Models\Package;
use App\Models\PromoCode;
use App\Models\Service;
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

    app(TenantContext::class)->set($secondTenant);

    foreach ($records as $record) {
        expect($record::query()->find($record->id))->toBeNull();
    }
});

test('a service cannot be created without tenant context', function () {
    $tenant = catalogTenant('Travel Agency', 'travel-agency');
    app(TenantContext::class)->set($tenant);
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

    app(TenantContext::class)->set($secondTenant);

    expect(fn () => FixedDeparture::query()->create([
        'package_id' => $package->id,
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-03',
        'total_slots' => 5,
    ]))->toThrow(LogicException::class);

    expect(fn () => GroupDiscountTier::query()->create([
        'package_id' => $package->id,
        'min_pax' => 2,
        'discount_type' => 'percentage',
        'discount_value' => 5,
    ]))->toThrow(LogicException::class);
});

test('promo codes and gift vouchers are isolated between tenants', function () {
    $firstTenant = catalogTenant('First Agency', 'first-agency');
    $secondTenant = catalogTenant('Second Agency', 'second-agency');

    app(TenantContext::class)->set($firstTenant);
    $promoCode = PromoCode::query()->create([
        'code' => 'SUMMER10',
        'discount_type' => 'percent',
        'discount_value' => 10,
    ]);
    $giftVoucher = GiftVoucher::query()->create([
        'code' => 'GIFT-100',
        'value' => 10000,
        'currency' => 'USD',
    ]);

    expect(PromoCode::query()->withoutGlobalScopes()->find($promoCode->id)->tenant_id)->toBe($firstTenant->id)
        ->and(GiftVoucher::query()->withoutGlobalScopes()->find($giftVoucher->id)->tenant_id)->toBe($firstTenant->id);

    app(TenantContext::class)->set($secondTenant);

    expect(PromoCode::query()->find($promoCode->id))->toBeNull()
        ->and(GiftVoucher::query()->find($giftVoucher->id))->toBeNull();
});
