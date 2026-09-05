<?php

use App\Filament\Tenant\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Tenant\Resources\RoleResource;
use App\Models\Booking;
use App\Models\BookingTraveler;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\Lead;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Policies\BookingPolicy;
use App\Policies\BookingTravelerPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\LeadPolicy;
use App\Policies\RolePolicy;
use App\Policies\TenantUserPolicy;
use App\Services\LeadConversion;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function crmTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

test('customers and leads are isolated between tenants', function () {
    $firstTenant = crmTenant('First Agency', 'first-agency');
    $secondTenant = crmTenant('Second Agency', 'second-agency');

    app(TenantContext::class)->set($firstTenant);
    $customer = Customer::factory()->create(['email' => 'customer@example.com']);
    $lead = Lead::query()->create([
        'customer_id' => $customer->id,
        'origin' => 'referral',
        'destination' => 'Pokhara',
    ]);

    app(TenantContext::class)->set($secondTenant);

    expect(Customer::query()->find($customer->id))->toBeNull()
        ->and(Lead::query()->find($lead->id))->toBeNull();
    expect(Customer::query()->withoutGlobalScopes()->find($customer->id)->tenant_id)
        ->toBe($firstTenant->id);
});

test('booking policy restricts booking access to authorized tenant staff', function () {
    $tenant = crmTenant('First Agency', 'first-agency');
    $otherTenant = crmTenant('Second Agency', 'second-agency');
    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $owner->assignRole(Role::create([
        'name' => 'Tenant Owner',
        'guard_name' => 'tenant',
        'team_id' => $tenant->id,
    ]));
    $booking = Booking::query()->create([
        'customer_id' => Customer::factory()->create()->id,
        'trip_name' => 'Local trip',
    ]);
    app(TenantContext::class)->set($otherTenant);
    $otherStaff = TenantUser::factory()->create();
    app(TenantContext::class)->set($tenant);
    $owner = $owner->fresh();

    expect((new BookingPolicy)->view($owner, $booking))->toBeTrue()
        ->and((new BookingPolicy)->view($otherStaff, $booking))->toBeFalse();
});

test('a tenant staff member can create a booking for a brand-new guest customer inline', function () {
    $tenant = crmTenant('First Agency', 'first-agency');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);

    Filament::setCurrentPanel('tenant');

    $test = Livewire::actingAs($owner, 'tenant')->test(CreateBooking::class)
        ->mountAction(TestAction::make('createOption')->schemaComponent('customer_id'))
        ->set('mountedActions.0.data.name', 'Walk-in Guest')
        ->set('mountedActions.0.data.email', 'guest@example.test')
        ->set('mountedActions.0.data.phone', '555-0100')
        ->set('mountedActions.0.data.type', 'individual')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $guest = Customer::query()->where('email', 'guest@example.test')->firstOrFail();

    expect($guest->tenant_id)->toBe($tenant->id)
        ->and((int) $test->get('data.customer_id'))->toBe($guest->id);

    $test->set('data.trip_name', 'Guest Getaway')
        ->set('data.status', 'pending')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Booking::query()->where('customer_id', $guest->id)->where('trip_name', 'Guest Getaway')->exists())->toBeTrue();
});

test('a sales agent, not just the tenant owner, can create a booking directly', function () {
    $tenant = crmTenant('First Agency', 'first-agency');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $sales = TenantUser::factory()->create();
    $salesRole = Role::query()->where('name', 'Sales Agent')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $sales->assignRole($salesRole);
    $customer = Customer::factory()->create();

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($sales, 'tenant')->test(CreateBooking::class)
        ->assertOk()
        ->set('data.customer_id', $customer->id)
        ->set('data.trip_name', 'Direct Sale Trip')
        ->set('data.status', 'pending')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Booking::query()->where('customer_id', $customer->id)->where('trip_name', 'Direct Sale Trip')->exists())->toBeTrue();
});

test('booking travelers are encrypted and isolated by tenant', function () {
    $tenant = crmTenant('First Agency', 'first-agency');
    $otherTenant = crmTenant('Second Agency', 'second-agency');

    app(TenantContext::class)->set($tenant);
    $staff = TenantUser::factory()->create();
    $role = Role::create([
        'name' => 'Operations',
        'guard_name' => 'tenant',
        'team_id' => $tenant->id,
    ]);
    $staff->assignRole($role);
    $booking = Booking::query()->create([
        'customer_id' => Customer::factory()->create()->id,
        'trip_name' => 'Local trip',
    ]);
    $traveler = BookingTraveler::query()->create([
        'booking_id' => $booking->id,
        'name' => 'Asha Traveler',
        'passport_no' => 'P1234567',
        'document_status' => 'pending',
    ]);

    expect($traveler->passport_no)->toBe('P1234567')
        ->and(BookingTraveler::query()->withoutGlobalScopes()->find($traveler->id)->tenant_id)
        ->toBe($tenant->id)
        ->and((new BookingTravelerPolicy)->view($staff, $traveler))->toBeTrue();

    app(TenantContext::class)->set($otherTenant);

    expect(BookingTraveler::query()->find($traveler->id))->toBeNull();
});

test('a lead converts to a booking and reserves fixed departure capacity', function () {
    $tenant = crmTenant('Travel Agency', 'travel-agency');
    app(TenantContext::class)->set($tenant);
    $staff = TenantUser::factory()->create();
    $customer = Customer::factory()->create();
    $package = Package::query()->create([
        'name' => 'Mountain Escape',
        'slug' => 'mountain-escape',
        'package_code' => 'ME-01',
        'duration_days' => 4,
        'sales_price' => 125000,
        'itinerary' => [['title' => 'Arrival', 'description' => 'Welcome']],
    ]);
    $departure = FixedDeparture::query()->create([
        'package_id' => $package->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-04',
        'total_slots' => 5,
    ]);
    $lead = Lead::query()->create([
        'origin' => 'manual',
        'destination' => 'Mountain Escape',
        'pax_count' => 2,
        'status' => 'negotiating',
    ]);

    $booking = app(LeadConversion::class)->convert($lead, $customer, $package->id, $departure, $staff);

    expect($booking->lead_id)->toBe($lead->id)
        ->and($booking->customer_id)->toBe($customer->id)
        ->and($booking->booked_itinerary)->toBe($package->itinerary)
        ->and($booking->total_amount)->toBe(125000);
    expect($departure->fresh()->booked_slots)->toBe(2);
    expect($lead->fresh()->status)->toBe('won');
});

test('lead conversion rejects customers from another tenant and duplicate conversion', function () {
    $firstTenant = crmTenant('First Agency', 'first-agency');
    $secondTenant = crmTenant('Second Agency', 'second-agency');
    app(TenantContext::class)->set($firstTenant);
    $lead = Lead::query()->create(['origin' => 'manual']);

    app(TenantContext::class)->set($secondTenant);
    $customer = Customer::factory()->create();
    app(TenantContext::class)->set($firstTenant);

    expect(fn () => app(LeadConversion::class)->convert($lead, $customer))
        ->toThrow(LogicException::class);

    $localCustomer = Customer::factory()->create();
    app(LeadConversion::class)->convert($lead, $localCustomer);

    expect(fn () => app(LeadConversion::class)->convert($lead, $localCustomer))
        ->toThrow(LogicException::class);
});

test('staff roles are scoped to a tenant and can be assigned to staff', function () {
    $firstTenant = crmTenant('First Agency', 'first-agency');
    $secondTenant = crmTenant('Second Agency', 'second-agency');

    app(TenantContext::class)->set($firstTenant);
    $staff = TenantUser::factory()->create();
    $role = Role::create([
        'name' => 'Sales Agent',
        'guard_name' => 'tenant',
        'team_id' => $firstTenant->id,
    ]);
    $staff->assignRole($role);

    app(TenantContext::class)->set($secondTenant);
    $otherRole = Role::create([
        'name' => 'Operations',
        'guard_name' => 'tenant',
        'team_id' => $secondTenant->id,
    ]);

    expect(RoleResource::getEloquentQuery()->whereKey($role->id)->exists())->toBeFalse()
        ->and(RoleResource::getEloquentQuery()->whereKey($otherRole->id)->exists())->toBeTrue();

    app(TenantContext::class)->set($firstTenant);
    expect($staff->fresh()->hasRole('Sales Agent'))->toBeTrue();
});

test('CRM policies enforce tenant ownership and staff responsibilities', function () {
    $tenant = crmTenant('First Agency', 'first-agency');
    $otherTenant = crmTenant('Second Agency', 'second-agency');
    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::create(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]);
    $owner->assignRole($ownerRole);
    $sales = TenantUser::factory()->create();
    $salesRole = Role::create(['name' => 'Sales Agent', 'guard_name' => 'tenant', 'team_id' => $tenant->id]);
    $sales->assignRole($salesRole);
    $customer = Customer::factory()->create();
    $lead = Lead::query()->create(['origin' => 'manual']);
    app(TenantContext::class)->set($otherTenant);
    $otherCustomer = Customer::withoutGlobalScopes()->create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Other Customer',
        'email' => 'other@example.com',
    ]);
    app(TenantContext::class)->set($tenant);

    expect((new CustomerPolicy)->update($sales, $customer)->allowed())->toBeTrue()
        ->and((new CustomerPolicy)->update($sales, $otherCustomer)->denied())->toBeTrue()
        ->and((new LeadPolicy)->update($sales, $lead)->allowed())->toBeTrue()
        ->and((new TenantUserPolicy)->create($owner))->toBeTrue()
        ->and((new RolePolicy)->create($owner))->toBeTrue();
});
