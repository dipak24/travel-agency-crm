<?php

use App\Filament\Tenant\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Tenant\Resources\BookingTravelerResource\Pages\ListBookingTravelers;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function bookingListOwner(): TenantUser
{
    test()->seed();

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('tenant');

    $owner = TenantUser::factory()->create();
    $owner->assignRole(Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail());

    return $owner;
}

test('cancel booking lives inside the row actions menu', function () {
    $owner = bookingListOwner();
    $booking = Booking::query()->create(['customer_id' => Customer::factory()->create()->id, 'trip_name' => 'Annapurna Circuit', 'status' => 'confirmed']);

    Livewire::actingAs($owner, 'tenant')->test(ListBookings::class)
        ->assertTableActionExists('cancelBooking', record: $booking)
        ->callAction(TestAction::make('cancelBooking')->table($booking));

    expect($booking->refresh()->status)->toBe('cancelled');
});

test('the list shows trip start and end dates, falling back to the fixed departure', function () {
    $owner = bookingListOwner();
    $customer = Customer::factory()->create();
    $package = Package::query()->create(['name' => 'Manaslu', 'slug' => 'manaslu', 'package_code' => 'MAN-1', 'duration_days' => 14]);
    $departure = FixedDeparture::query()->create([
        'package_id' => $package->id, 'start_date' => '2026-11-01', 'end_date' => '2026-11-14', 'total_slots' => 10, 'status' => 'open',
    ]);
    $ownDates = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Custom trek', 'start_date' => '2026-10-05', 'end_date' => '2026-10-12']);
    $viaDeparture = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Manaslu group', 'fixed_departure_id' => $departure->id]);

    Livewire::actingAs($owner, 'tenant')->test(ListBookings::class)
        ->assertTableColumnFormattedStateSet('start_date', 'Oct 5, 2026', $ownDates)
        ->assertTableColumnFormattedStateSet('end_date', 'Oct 12, 2026', $ownDates)
        ->assertTableColumnFormattedStateSet('start_date', 'Nov 1, 2026', $viaDeparture)
        ->assertTableColumnFormattedStateSet('end_date', 'Nov 14, 2026', $viaDeparture);
});

test('bookings can be searched by trip name and by the customer name, email or phone', function () {
    $owner = bookingListOwner();
    $pema = Customer::factory()->create(['name' => 'Pema Sherpa', 'email' => 'pema@example.test', 'phone' => '+977 9841234567']);
    $john = Customer::factory()->create(['name' => 'John Walker', 'email' => 'john@example.test', 'phone' => '+1 202 555 0101']);
    $pemaTrip = Booking::query()->create(['customer_id' => $pema->id, 'trip_name' => 'Everest Base Camp']);
    $johnTrip = Booking::query()->create(['customer_id' => $john->id, 'trip_name' => 'Langtang Valley']);

    $list = Livewire::actingAs($owner, 'tenant')->test(ListBookings::class);

    $list->searchTable('pema sherpa')->assertCanSeeTableRecords([$pemaTrip])->assertCanNotSeeTableRecords([$johnTrip]);
    $list->searchTable('john@example')->assertCanSeeTableRecords([$johnTrip])->assertCanNotSeeTableRecords([$pemaTrip]);
    $list->searchTable('9841')->assertCanSeeTableRecords([$pemaTrip])->assertCanNotSeeTableRecords([$johnTrip]);
    $list->searchTable('langtang')->assertCanSeeTableRecords([$johnTrip])->assertCanNotSeeTableRecords([$pemaTrip]);
});

test('bookings can be filtered by trip name and trip start date', function () {
    $owner = bookingListOwner();
    $customer = Customer::factory()->create();
    $october = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp', 'start_date' => '2026-10-05']);
    $december = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp', 'start_date' => '2026-12-20']);
    $other = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Langtang Valley', 'start_date' => '2026-10-10']);

    Livewire::actingAs($owner, 'tenant')->test(ListBookings::class)
        ->filterTable('trip_name', 'Everest Base Camp')
        ->assertCanSeeTableRecords([$october, $december])
        ->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('trip_date', ['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertCanSeeTableRecords([$october, $other])
        ->assertCanNotSeeTableRecords([$december]);
});

test('travellers can be searched by name, email or phone', function () {
    $owner = bookingListOwner();
    $booking = Booking::query()->create(['customer_id' => Customer::factory()->create()->id, 'trip_name' => 'Everest Base Camp']);
    $ang = $booking->travelers()->create(['name' => 'Ang Dorje', 'email' => 'ang@example.test', 'phone' => '+977 9841000000']);
    $mia = $booking->travelers()->create(['name' => 'Mia Jones', 'email' => 'mia@example.test', 'phone' => '+44 7700 900123']);

    $list = Livewire::actingAs($owner, 'tenant')->test(ListBookingTravelers::class);

    $list->searchTable('dorje')->assertCanSeeTableRecords([$ang])->assertCanNotSeeTableRecords([$mia]);
    $list->searchTable('mia@example')->assertCanSeeTableRecords([$mia])->assertCanNotSeeTableRecords([$ang]);
    $list->searchTable('9841')->assertCanSeeTableRecords([$ang])->assertCanNotSeeTableRecords([$mia]);
});
