<?php

use App\Filament\Widgets\PlatformActivityOverview;
use App\Filament\Widgets\TenantOverview;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Pulls a widget's protected getStats() via reflection — precise and avoids the fragility of
 * scraping rendered HTML for bare digits, which also turn up incidentally in asset version
 * strings/wire ids. Page-rendering itself is covered separately by the "renders" tests below.
 */
function adwWidgetStats(object $widget): array
{
    return (new ReflectionMethod($widget, 'getStats'))->invoke($widget);
}

test('the admin dashboard renders all platform widgets', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    $this->actingAs($admin, 'super_admin')->get('/admin')
        ->assertOk()
        ->assertSee('Total tenants')
        ->assertSee('Recent signups')
        ->assertSee('Total bookings')
        ->assertSee('Top-performing tenants');
});

test('tenant overview counts tenants accurately by status', function () {
    $this->seed();

    Tenant::query()->create(['name' => 'Active Co', 'slug' => 'active-co', 'status' => 'active']);
    Tenant::query()->create(['name' => 'Suspended Co', 'slug' => 'suspended-co', 'status' => 'suspended']);

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $this->actingAs($admin, 'super_admin');

    // demo-travel (trial, from the seeder) + Active Co + Suspended Co = 3 total.
    $stats = adwWidgetStats(new TenantOverview);

    expect($stats[0]->getValue())->toBe(3)
        ->and($stats[1]->getValue())->toBe(1) // active
        ->and($stats[2]->getValue())->toBe(1) // trial
        ->and($stats[3]->getValue())->toBe(1); // suspended
});

test('platform activity totals bookings and revenue across every tenant, not just one', function () {
    $this->seed();

    $tenantA = Tenant::query()->create(['name' => 'A Travel', 'slug' => 'a-travel', 'currency' => 'USD']);
    app(TenantContext::class)->set($tenantA);
    $customerA = Customer::factory()->create();
    Booking::query()->create(['customer_id' => $customerA->id, 'trip_name' => 'A trip']);
    $invoiceA = Invoice::query()->create(['customer_id' => $customerA->id, 'amount' => 5000, 'total' => 5000, 'currency' => 'USD', 'status' => 'paid']);
    Payment::query()->create(['invoice_id' => $invoiceA->id, 'amount' => 5000, 'currency' => 'USD', 'method' => 'cash', 'type' => 'full', 'status' => 'completed']);

    $tenantB = Tenant::query()->create(['name' => 'B Travel', 'slug' => 'b-travel', 'currency' => 'USD']);
    app(TenantContext::class)->set($tenantB);
    $customerB = Customer::factory()->create();
    Booking::query()->create(['customer_id' => $customerB->id, 'trip_name' => 'B trip']);
    Booking::query()->create(['customer_id' => $customerB->id, 'trip_name' => 'B trip 2']);
    $invoiceB = Invoice::query()->create(['customer_id' => $customerB->id, 'amount' => 7000, 'total' => 7000, 'currency' => 'USD', 'status' => 'paid']);
    Payment::query()->create(['invoice_id' => $invoiceB->id, 'amount' => 7000, 'currency' => 'USD', 'method' => 'cash', 'type' => 'full', 'status' => 'completed']);

    app(TenantContext::class)->clear();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $this->actingAs($admin, 'super_admin');

    // 3 bookings total across A Travel (1) and B Travel (2); $50 + $70 = $120 completed revenue.
    $stats = adwWidgetStats(new PlatformActivityOverview);

    expect($stats[0]->getValue())->toBe(3)
        ->and($stats[2]->getValue())->toBe('USD 120.00');

    $this->actingAs($admin, 'super_admin')->get('/admin')
        ->assertOk()
        ->assertSee('B Travel'); // ranked first — 2 bookings beats A Travel's 1
});

test('a platform staff member without manage tenants or manage billing permission does not see the dashboard widgets', function () {
    $this->seed();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $staff = SuperAdmin::factory()->create();
    $staff->assignRole(Role::create(['name' => 'Support', 'guard_name' => 'super_admin', 'team_id' => 0]));

    $response = $this->actingAs($staff, 'super_admin')->get('/admin');

    $response->assertOk()
        ->assertDontSee('Total tenants')
        ->assertDontSee('Recent signups')
        ->assertDontSee('Top-performing tenants');
});
