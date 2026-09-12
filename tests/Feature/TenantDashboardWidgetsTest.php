<?php

use App\Filament\Tenant\Reports\BookingStatusBreakdown;
use App\Filament\Tenant\Reports\LeadConversionOverview;
use App\Filament\Tenant\Widgets\LeadsPipeline;
use App\Filament\Tenant\Widgets\OperationsOverview;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Visiting a real Filament page renders the full sidebar nav, which checks
 * granular permissions like `view customers` on every resource's canAccess()
 * — those only exist once PermissionSeeder has run for this tenant.
 */
function tdwTenant(string $name, string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => $name, 'slug' => $slug, 'currency' => 'USD']);
    (new PermissionSeeder)->run();

    return $tenant;
}

function tdwOwner(Tenant $tenant): TenantUser
{
    $owner = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $role = Role::firstOrCreate(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]);
    $owner->assignRole($role);

    return $owner;
}

/**
 * Pulls a widget's protected data method (getStats()/getData()) via reflection. Real page
 * rendering is still exercised by the "renders without error" tests below — this is only for
 * asserting the underlying numbers are correct without the fragility of scraping rendered HTML
 * (any short digit like "1" or "5" also turns up incidentally in asset version strings/wire ids).
 */
function tdwWidgetData(object $widget, string $method): mixed
{
    $reflection = new ReflectionMethod($widget, $method);

    return $reflection->invoke($widget);
}

test('the tenant dashboard renders all the operational widgets', function () {
    $tenant = tdwTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tdwOwner($tenant);

    $this->actingAs($owner, 'tenant')->get('/tenant')
        ->assertOk()
        ->assertSee('Bookings this month')
        ->assertSee('Pending invoices')
        ->assertSee('Upcoming trips (30 days)')
        ->assertSee('Open leads')
        ->assertSee('Leads pipeline')
        ->assertSee('Upcoming trips')
        ->assertSee('Staff performance');
});

test('the reports page renders the revenue, conversion and booking-status widgets', function () {
    $tenant = tdwTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tdwOwner($tenant);

    $this->actingAs($owner, 'tenant')->get('/tenant/reports')
        ->assertOk()
        ->assertSee('Total leads')
        ->assertSee('Conversion rate')
        ->assertSee('Revenue by month')
        ->assertSee('Bookings by status')
        ->assertSee('Staff performance');
});

test('operations overview computes accurate bookings-this-month, pending-invoices, upcoming-trips and open-leads figures', function () {
    $tenant = tdwTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tdwOwner($tenant);
    $customer = Customer::factory()->create();

    // Bookings this month: 3 created "now"; upcoming trips (30 days): only the
    // confirmed one 10 days out — the +40 day one is outside the window and
    // the cancelled one is excluded by status regardless of its date.
    Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Confirmed upcoming', 'status' => 'confirmed', 'start_date' => now()->addDays(10)]);
    Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Far out', 'status' => 'pending', 'start_date' => now()->addDays(40)]);
    Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Cancelled but soon', 'status' => 'cancelled', 'start_date' => now()->addDays(5)]);

    // Open leads (new + contacted): 2; won + lost are not "open".
    Lead::query()->create(['status' => 'new']);
    Lead::query()->create(['status' => 'contacted']);
    Lead::query()->create(['status' => 'won']);
    Lead::query()->create(['status' => 'lost']);

    Invoice::query()->create(['customer_id' => $customer->id, 'amount' => 10000, 'total' => 10000, 'currency' => 'USD', 'status' => 'issued']);

    $this->actingAs($owner, 'tenant');
    $stats = tdwWidgetData(new OperationsOverview, 'getStats');

    expect($stats[0]->getValue())->toBe(3) // bookings this month
        ->and($stats[1]->getValue())->toBe(1) // pending invoices
        ->and($stats[1]->getDescription())->toBe('USD 100.00 outstanding')
        ->and($stats[2]->getValue())->toBe(1) // upcoming trips
        ->and($stats[3]->getValue())->toBe(2); // open leads
});

test('leads pipeline groups leads by status in the fixed new/contacted/negotiating/won/lost order', function () {
    $tenant = tdwTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tdwOwner($tenant);

    Lead::query()->create(['status' => 'new']);
    Lead::query()->create(['status' => 'new']);
    Lead::query()->create(['status' => 'won']);

    $this->actingAs($owner, 'tenant');
    $data = tdwWidgetData(new LeadsPipeline, 'getData');

    expect($data['labels'])->toBe(['New', 'Contacted', 'Negotiating', 'Won', 'Lost'])
        ->and($data['datasets'][0]['data'])->toBe([2, 0, 0, 1, 0]);
});

test('staff performance counts assigned leads, won leads and created bookings per staff member', function () {
    $tenant = tdwTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tdwOwner($tenant);
    $customer = Customer::factory()->create();

    Lead::query()->create(['status' => 'won', 'assigned_staff_id' => $owner->id]);
    Lead::query()->create(['status' => 'new', 'assigned_staff_id' => $owner->id]);
    Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Owner booking', 'created_by_staff_id' => $owner->id]);

    // Exercises the same TenantUser::assignedLeads()/createdBookings() relations the
    // StaffPerformance widget's own table query uses (see StaffPerformance::table()).
    $ownerRow = TenantUser::query()
        ->withCount([
            'assignedLeads',
            'assignedLeads as leads_won_count' => fn ($query) => $query->where('status', 'won'),
            'createdBookings',
        ])
        ->findOrFail($owner->id);

    expect($ownerRow->assigned_leads_count)->toBe(2)
        ->and($ownerRow->leads_won_count)->toBe(1)
        ->and($ownerRow->created_bookings_count)->toBe(1);

    $this->actingAs($owner, 'tenant')->get('/tenant')->assertOk()->assertSee($owner->name);
});

test('lead conversion overview computes the correct conversion rate', function () {
    $tenant = tdwTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tdwOwner($tenant);

    Lead::query()->create(['status' => 'won']);
    Lead::query()->create(['status' => 'lost']);
    Lead::query()->create(['status' => 'new']);
    Lead::query()->create(['status' => 'new']);
    Lead::query()->create(['status' => 'new']);

    $this->actingAs($owner, 'tenant');
    $stats = tdwWidgetData(new LeadConversionOverview, 'getStats');

    expect($stats[0]->getValue())->toBe(5)
        ->and($stats[1]->getValue())->toBe(1)
        ->and($stats[2]->getValue())->toBe(1)
        ->and($stats[3]->getValue())->toBe('20%');
});

test('booking status breakdown groups bookings by status in the fixed order', function () {
    $tenant = tdwTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tdwOwner($tenant);
    $customer = Customer::factory()->create();

    Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'A', 'status' => 'confirmed']);
    Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'B', 'status' => 'confirmed']);
    Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'C', 'status' => 'cancelled']);

    $this->actingAs($owner, 'tenant');
    $data = tdwWidgetData(new BookingStatusBreakdown, 'getData');

    expect($data['labels'])->toBe(['Pending', 'Confirmed', 'Ongoing', 'Completed', 'Cancelled'])
        ->and($data['datasets'][0]['data'])->toBe([0, 2, 0, 0, 1]);
});

test('the dashboard and reports pages require an authenticated tenant user', function () {
    $this->get('/tenant')->assertRedirect();
    $this->get('/tenant/reports')->assertRedirect();
});

test('a staff member without any special permission can still view the dashboard and reports', function () {
    $tenant = tdwTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $staff = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $staff->assignRole(Role::firstOrCreate(['name' => 'Sales Agent', 'guard_name' => 'tenant', 'team_id' => $tenant->id]));

    $this->actingAs($staff, 'tenant')->get('/tenant')->assertOk();
    $this->actingAs($staff, 'tenant')->get('/tenant/reports')->assertOk();
});
