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

/**
 * A `ListRecords` page in this project does not get a header "Create" button
 * for free — unlike Filament's own default, every list page here must
 * explicitly override `getHeaderActions()` and return `[CreateAction::make()]`
 * (see `.ai/rules` and PaymentResource\Pages\ListPayments for the working
 * convention). `ListBookings`, `ListBookingTravelers`, and `ListInvoices` were
 * missing this override entirely — a tenant, staff or owner, had no way to
 * reach the create page from the index at all. This asserts the create link
 * is actually present in the rendered index page for every resource, not
 * just that `/create` works when hit directly.
 */
test('every tenant panel resource index page has a working create button', function () {
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
        $this->actingAs($staff, 'tenant')
            ->get("/tenant/{$resource}")
            ->assertOk()
            ->assertSee("/tenant/{$resource}/create", false);
    }
});

/**
 * Every tenant panel page should render edge-to-edge (`fi-width-full`)
 * instead of Filament's default constrained `7xl` container — set panel-wide
 * via `TenantPanelProvider::maxContentWidth(Width::Full)` rather than only on
 * individual Create/Edit pages, so a newly added page can't silently regress
 * back to the narrow default.
 */
test('the tenant panel renders pages at full width', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();

    $this->actingAs($staff, 'tenant')
        ->get('/tenant/bookings')
        ->assertOk()
        ->assertSee('fi-width-full', false);
});
