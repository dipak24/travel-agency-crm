<?php

use App\Models\Package;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('package catalog records are isolated between tenants', function () {
    $firstTenant = Tenant::query()->create(['name' => 'First Agency', 'slug' => 'first-agency']);
    $secondTenant = Tenant::query()->create(['name' => 'Second Agency', 'slug' => 'second-agency']);

    app(TenantContext::class)->set($firstTenant);
    $package = Package::query()->create([
        'name' => 'First Package',
        'slug' => 'first-package',
        'package_code' => 'FIRST-01',
        'duration_days' => 5,
        'itinerary' => [
            ['title' => 'Arrival', 'description' => 'Airport transfer and hotel check-in.'],
        ],
    ]);

    app(TenantContext::class)->set($secondTenant);

    expect(Package::query()->find($package->id))->toBeNull()
        ->and(Package::query()->count())->toBe(0)
        ->and(Package::query()->withoutGlobalScopes()->find($package->id)->tenant_id)
        ->toBe($firstTenant->id);
});

test('package itinerary is persisted as structured data', function () {
    $tenant = Tenant::query()->create(['name' => 'Travel Agency', 'slug' => 'travel-agency']);
    app(TenantContext::class)->set($tenant);

    $package = Package::query()->create([
        'name' => 'Mountain Escape',
        'slug' => 'mountain-escape',
        'package_code' => 'ME-01',
        'duration_days' => 3,
        'itinerary' => [
            ['title' => 'Day 1', 'description' => 'Arrival.'],
            ['title' => 'Day 2', 'description' => 'Hike.'],
        ],
    ]);

    expect($package->fresh()->itinerary)->toHaveCount(2)
        ->and($package->fresh()->itinerary[1]['title'])->toBe('Day 2');
});
