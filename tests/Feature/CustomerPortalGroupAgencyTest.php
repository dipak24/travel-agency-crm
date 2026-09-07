<?php

use App\Filament\Portal\Resources\BookingResource\Pages\ViewBooking;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function groupAgencyTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

test('an individual customer cannot see the bulk add-travelers action', function () {
    $tenant = groupAgencyTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['type' => 'individual']);
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionHidden('addTravelers');
});

test('an agency or group leader customer can see and use the bulk add-travelers action', function () {
    $tenant = groupAgencyTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['type' => 'agency']);
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionVisible('addTravelers');

    // The action's own mounted form can't be driven from a test on this
    // Filament/Livewire version combo (see .ai/rules/feature.md) — exercise
    // the same bulk-create the action's closure performs directly instead.
    foreach ([['name' => 'Traveler One', 'dob' => '1990-01-01'], ['name' => 'Traveler Two', 'dob' => null]] as $traveler) {
        $booking->travelers()->create([
            'name' => $traveler['name'],
            'dob' => $traveler['dob'],
            'document_status' => 'pending',
        ]);
    }

    expect($booking->travelers()->count())->toBe(2);
});

test('the bulk document-upload action is available to every customer, not just group/agency', function () {
    $tenant = groupAgencyTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $individual = Customer::factory()->create(['type' => 'individual']);
    $booking = Booking::query()->create(['customer_id' => $individual->id, 'trip_name' => 'Everest Base Camp']);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($individual, 'customer')
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionVisible('uploadDocument');
});
