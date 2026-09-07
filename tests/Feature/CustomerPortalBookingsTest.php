<?php

use App\Filament\Portal\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Portal\Resources\BookingResource\Pages\ViewBooking;
use App\Models\Booking;
use App\Models\BookingIncludeExclude;
use App\Models\BookingTraveler;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function bookingsTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

test('a customer only sees their own bookings in the portal, isolated from other customers and tenants', function () {
    $tenant = bookingsTenant('Northwind Travel', 'northwind-travel');
    $otherTenant = bookingsTenant('Blue Peak Journeys', 'blue-peak-journeys');

    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $otherCustomerSameTenant = Customer::factory()->create();
    $ownBooking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'My Trip']);
    Booking::query()->create(['customer_id' => $otherCustomerSameTenant->id, 'trip_name' => 'Someone Else Trip']);

    app(TenantContext::class)->set($otherTenant);
    $otherTenantCustomer = Customer::factory()->create();
    Booking::query()->create(['customer_id' => $otherTenantCustomer->id, 'trip_name' => 'Other Tenant Trip']);

    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(ListBookings::class)
        ->assertCanSeeTableRecords([$ownBooking])
        ->assertCountTableRecords(1);
});

test('a customer cannot view another customer\'s booking detail page', function () {
    $tenant = bookingsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $otherCustomer = Customer::factory()->create();
    $otherBooking = Booking::query()->create(['customer_id' => $otherCustomer->id, 'trip_name' => 'Not Yours']);

    // 404, not 403 — the resource's own query scope excludes other customers'
    // bookings entirely, so the record never resolves via route-model
    // binding in the first place. This also avoids leaking whether a booking
    // with that ID exists at all.
    $this->actingAs($customer, 'customer')->get("/portal/bookings/{$otherBooking->id}")->assertNotFound();
});

test('the current/past tabs split bookings by end date', function () {
    $tenant = bookingsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $upcoming = Booking::query()->create([
        'customer_id' => $customer->id, 'trip_name' => 'Upcoming Trip', 'end_date' => today()->addMonth(),
    ]);
    $past = Booking::query()->create([
        'customer_id' => $customer->id, 'trip_name' => 'Past Trip', 'end_date' => today()->subMonth(),
    ]);

    Filament::setCurrentPanel('portal');

    $test = Livewire::actingAs($customer, 'customer')->test(ListBookings::class);

    $test->set('activeTab', 'current')->assertCanSeeTableRecords([$upcoming])->assertCanNotSeeTableRecords([$past]);
    $test->set('activeTab', 'past')->assertCanSeeTableRecords([$past])->assertCanNotSeeTableRecords([$upcoming]);
});

test('a customer can view their booking itinerary, include/exclude list, and travelers', function () {
    $tenant = bookingsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $booking = Booking::query()->create([
        'customer_id' => $customer->id,
        'trip_name' => 'Everest Base Camp',
        'booked_itinerary' => [['title' => 'Day 1', 'description' => 'Arrival in Kathmandu']],
    ]);
    BookingIncludeExclude::query()->create([
        'booking_id' => $booking->id, 'type' => 'include', 'title' => 'Airport transfer',
    ]);
    BookingTraveler::query()->create([
        'booking_id' => $booking->id, 'name' => 'Asha Traveler', 'document_status' => 'verified',
    ]);

    $this->actingAs($customer, 'customer')
        ->get("/portal/bookings/{$booking->id}")
        ->assertOk()
        ->assertSee('Day 1')
        ->assertSee('Arrival in Kathmandu')
        ->assertSee('Airport transfer')
        ->assertSee('Asha Traveler');
});

test('a customer can leave a note for staff, and staff can see it read-only on the booking edit page', function () {
    $tenant = bookingsTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);

    Filament::setCurrentPanel('portal');

    // The Livewire/Filament version combo here can't reliably drive a mounted
    // action's own form fields from a test (see .ai/rules/feature.md) — so
    // this confirms the action is actually wired up and reachable for the
    // customer on their own booking, and separately exercises the same
    // update the action's closure performs plus its read-only staff-side
    // display, rather than clicking through the modal itself.
    Livewire::actingAs($customer, 'customer')->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionExists('editNote');

    $booking->update(['customer_notes' => 'Please book a window seat.']);

    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);

    $this->actingAs($owner, 'tenant')
        ->get("/tenant/bookings/{$booking->id}/edit")
        ->assertOk()
        ->assertSee('Please book a window seat.');
});

test('an include/exclude item is tenant-isolated and snapshots its title/description', function () {
    $tenant = bookingsTenant('Northwind Travel', 'northwind-travel');
    $otherTenant = bookingsTenant('Blue Peak Journeys', 'blue-peak-journeys');

    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);
    $item = $booking->includeExcludes()->create([
        'type' => 'include',
        'title' => 'Luxury hotel',
        'description' => '5-star stay in Kathmandu',
    ]);

    expect(BookingIncludeExclude::query()->withoutGlobalScopes()->find($item->id)->tenant_id)->toBe($tenant->id);

    app(TenantContext::class)->set($otherTenant);

    expect(BookingIncludeExclude::query()->find($item->id))->toBeNull();
});
