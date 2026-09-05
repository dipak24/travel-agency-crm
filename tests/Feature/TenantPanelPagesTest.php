<?php

use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Smoke-checks that every tenant-panel resource's index/create pages render
 * without error. Several of these resources (FixedDeparture,
 * GroupDiscountTier, IncludeExclude, Service, ServiceAvailability) have no
 * other test coverage that actually renders their Filament pages —
 * CatalogModulesTest only exercises the underlying models/policies — so a
 * class-composition or import error in the Resource/Page classes would
 * otherwise go unnoticed.
 */
test('tenant owner can access every tenant panel resource index and create page', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();

    $resources = [
        'bookings', 'booking-travelers', 'customers', 'fixed-departures',
        'group-discount-tiers', 'include-excludes', 'invoices', 'leads',
        'packages', 'payments', 'roles', 'service-availabilities', 'services',
        'staff',
    ];

    foreach ($resources as $resource) {
        $this->actingAs($staff, 'tenant')->get("/tenant/{$resource}")->assertOk();
        $this->actingAs($staff, 'tenant')->get("/tenant/{$resource}/create")->assertOk();
    }
});
