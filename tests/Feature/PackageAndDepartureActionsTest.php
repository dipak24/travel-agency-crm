<?php

use App\Filament\Tenant\Resources\FixedDepartureResource\Pages\ListFixedDepartures;
use App\Filament\Tenant\Resources\PackageResource\Pages\ListPackages;
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

function padTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function padOwner(Tenant $tenant): TenantUser
{
    $owner = TenantUser::factory()->create();
    $role = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($role);

    return $owner;
}

function padPackage(array $overrides = []): Package
{
    return Package::query()->create(array_merge([
        'name' => 'K2 Base Camp', 'slug' => 'k2-'.uniqid(), 'package_code' => 'K2-'.uniqid(), 'duration_days' => 20,
    ], $overrides));
}

test('a package can be published and archived from quick table actions', function () {
    $tenant = padTenant('First Agency', 'first-agency');
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = padOwner($tenant);
    $package = padPackage(['status' => 'draft']);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListPackages::class)
        ->callAction(TestAction::make('publish')->table($package));

    expect($package->refresh()->status)->toBe('published');

    Livewire::actingAs($owner, 'tenant')->test(ListPackages::class)
        ->callAction(TestAction::make('archive')->table($package));

    expect($package->refresh()->status)->toBe('archived');
});

test('a package with bookings cannot be deleted, but an unused one can', function () {
    $tenant = padTenant('First Agency', 'first-agency');
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = padOwner($tenant);

    $unused = padPackage();
    $booked = padPackage();
    Booking::query()->create(['customer_id' => Customer::factory()->create()->id, 'trip_name' => 'Booked trip', 'package_id' => $booked->id]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListPackages::class)
        ->callAction(TestAction::make('delete')->table($booked));

    expect(Package::query()->whereKey($booked->id)->exists())->toBeTrue();

    Livewire::actingAs($owner, 'tenant')->test(ListPackages::class)
        ->callAction(TestAction::make('delete')->table($unused));

    expect(Package::query()->whereKey($unused->id)->exists())->toBeFalse();
});

test('a fixed departure can be closed and cancelled from quick table actions', function () {
    $tenant = padTenant('First Agency', 'first-agency');
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = padOwner($tenant);
    $package = padPackage();
    $departure = FixedDeparture::query()->create([
        'package_id' => $package->id, 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addDays(20),
        'total_slots' => 10, 'status' => 'open',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListFixedDepartures::class)
        ->callAction(TestAction::make('closeDeparture')->table($departure));

    expect($departure->refresh()->status)->toBe('closed');

    Livewire::actingAs($owner, 'tenant')->test(ListFixedDepartures::class)
        ->callAction(TestAction::make('cancelDeparture')->table($departure));

    expect($departure->refresh()->status)->toBe('cancelled');
});

test('a fixed departure with bookings cannot be deleted, but an unused one can', function () {
    $tenant = padTenant('First Agency', 'first-agency');
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = padOwner($tenant);
    $package = padPackage();
    $unused = FixedDeparture::query()->create([
        'package_id' => $package->id, 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addDays(20),
        'total_slots' => 10, 'status' => 'open',
    ]);
    $booked = FixedDeparture::query()->create([
        'package_id' => $package->id, 'start_date' => now()->addMonths(2), 'end_date' => now()->addMonths(2)->addDays(20),
        'total_slots' => 10, 'booked_slots' => 3, 'status' => 'open',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListFixedDepartures::class)
        ->callAction(TestAction::make('delete')->table($booked));

    expect(FixedDeparture::query()->whereKey($booked->id)->exists())->toBeTrue();

    Livewire::actingAs($owner, 'tenant')->test(ListFixedDepartures::class)
        ->callAction(TestAction::make('delete')->table($unused));

    expect(FixedDeparture::query()->whereKey($unused->id)->exists())->toBeFalse();
});
