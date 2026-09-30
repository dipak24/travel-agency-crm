<?php

use App\Filament\Tenant\Resources\FixedDepartureResource\Pages\ListFixedDepartures;
use App\Filament\Tenant\Resources\GroupDiscountTierResource\Pages\ListGroupDiscountTiers;
use App\Filament\Tenant\Resources\PackageResource\Pages\CreatePackage;
use App\Filament\Tenant\Resources\PackageResource\Pages\ListPackages;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\GroupDiscountTier;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\PublicBookingLinks;
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

test('a package can be published, archived and restored from quick table actions', function () {
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

    Livewire::actingAs($owner, 'tenant')->test(ListPackages::class)
        ->set('activeTab', 'archived')
        ->assertCanSeeTableRecords([$package])
        ->callAction(TestAction::make('restore')->table($package));

    expect($package->refresh()->status)->toBe('draft');
});

test('a package is created with a URL-safe unique code and a free-text itinerary', function () {
    $tenant = padTenant('First Agency', 'first-agency');
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = padOwner($tenant);
    padPackage(['package_code' => 'TAKEN-1']);

    Filament::setCurrentPanel('tenant');

    $form = Livewire::actingAs($owner, 'tenant')->test(CreatePackage::class);
    foreach ([
        'name' => 'Annapurna Circuit', 'slug' => 'annapurna-circuit', 'package_code' => 'ANN 15',
        'category' => 'trek', 'duration_days' => 15, 'base_price' => '900.00', 'sales_price' => '1200.00',
        'status' => 'draft', 'itinerary' => '<h2>Day 1</h2><p>Drive to Besisahar.</p>',
    ] as $field => $value) {
        $form->set("data.{$field}", $value);
    }

    $form->call('create')->assertHasFormErrors(['package_code' => 'regex']);
    $form->set('data.package_code', 'TAKEN-1')->call('create')->assertHasFormErrors(['package_code' => 'unique']);
    $form->set('data.package_code', 'ANN-15')->call('create')->assertHasNoFormErrors();

    $package = Package::query()->where('package_code', 'ANN-15')->firstOrFail();
    expect($package->status)->toBe('draft')
        ->and($package->itineraryHtml())->toContain('Drive to Besisahar.');
});

test('only archived packages can be deleted permanently, and never one with bookings', function () {
    $tenant = padTenant('First Agency', 'first-agency');
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = padOwner($tenant);

    $active = padPackage(['status' => 'published']);
    $unused = padPackage(['status' => 'archived']);
    $booked = padPackage(['status' => 'archived']);
    Booking::query()->create(['customer_id' => Customer::factory()->create()->id, 'trip_name' => 'Booked trip', 'package_id' => $booked->id]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListPackages::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$unused, $booked])
        ->assertActionHidden(TestAction::make('delete')->table($active));

    Livewire::actingAs($owner, 'tenant')->test(ListPackages::class)
        ->set('activeTab', 'archived')
        ->assertCanSeeTableRecords([$unused, $booked])
        ->assertCanNotSeeTableRecords([$active])
        ->callAction(TestAction::make('delete')->table($booked));

    expect(Package::query()->whereKey($booked->id)->exists())->toBeTrue();

    Livewire::actingAs($owner, 'tenant')->test(ListPackages::class)
        ->set('activeTab', 'archived')
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

test('staff copy a booking link for published packages and live departures only', function () {
    $tenant = padTenant('First Agency', 'first-agency');
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = padOwner($tenant);
    $public = padPackage(['status' => 'published', 'slug' => 'k2-base-camp-trek-with-gokyo-lakes', 'package_code' => 'K2-20']);
    $draft = padPackage(['status' => 'draft']);
    $departure = FixedDeparture::factory()->create(['package_id' => $public->id]);
    $cancelled = FixedDeparture::factory()->create(['package_id' => $public->id, 'status' => 'cancelled']);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListPackages::class)
        ->assertActionHidden(TestAction::make('bookingLink')->table($draft))
        ->assertActionVisible(TestAction::make('bookingLink')->table($public))
        ->mountAction(TestAction::make('bookingLink')->table($public))
        ->assertActionMounted(TestAction::make('bookingLink')->table($public));

    Livewire::actingAs($owner, 'tenant')->test(ListFixedDepartures::class)
        ->assertActionHidden(TestAction::make('bookingLink')->table($cancelled))
        ->assertActionVisible(TestAction::make('bookingLink')->table($departure));

    expect(PublicBookingLinks::package($tenant, $public))->toBe(portalUrl($tenant, '/book/K2-20'))
        ->and(PublicBookingLinks::departure($tenant, $departure))->toBe(portalUrl($tenant, "/departures/{$departure->id}/join"))
        ->and(PublicBookingLinks::widgetSnippet($tenant, 'K2-20'))
        ->toContain('data-package="K2-20"')
        ->toContain(portalUrl($tenant, '/js/departures-widget.js'));
});

test('fixed departures can be filtered by package, status and upcoming or past dates', function () {
    $tenant = padTenant('First Agency', 'first-agency');
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = padOwner($tenant);
    $everest = padPackage(['name' => 'Everest']);
    $annapurna = padPackage(['name' => 'Annapurna']);
    $upcoming = FixedDeparture::factory()->create(['package_id' => $everest->id, 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addDays(5), 'status' => 'open']);
    $past = FixedDeparture::factory()->create(['package_id' => $everest->id, 'start_date' => now()->subMonth(), 'end_date' => now()->subMonth()->addDays(5), 'status' => 'closed']);
    $other = FixedDeparture::factory()->create(['package_id' => $annapurna->id, 'start_date' => now()->addMonths(2), 'end_date' => now()->addMonths(2)->addDays(5), 'status' => 'cancelled']);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListFixedDepartures::class)
        ->filterTable('package_id', $everest->id)
        ->assertCanSeeTableRecords([$upcoming, $past])
        ->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('status', 'cancelled')
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$upcoming, $past])
        ->resetTableFilters()
        ->filterTable('upcoming', false)
        ->assertCanSeeTableRecords([$past])
        ->assertCanNotSeeTableRecords([$upcoming, $other]);
});

test('group discount tiers can be filtered by package (or agency-wide) and discount type', function () {
    $tenant = padTenant('First Agency', 'first-agency');
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = padOwner($tenant);
    $package = padPackage();
    $packageTier = GroupDiscountTier::query()->create(['package_id' => $package->id, 'min_pax' => 4, 'discount_type' => 'percentage', 'discount_value' => 10]);
    $agencyTier = GroupDiscountTier::query()->create(['package_id' => null, 'min_pax' => 6, 'discount_type' => 'fixed', 'discount_value' => 5000]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListGroupDiscountTiers::class)
        ->filterTable('package', $package->id)
        ->assertCanSeeTableRecords([$packageTier])
        ->assertCanNotSeeTableRecords([$agencyTier])
        ->filterTable('package', 'all')
        ->assertCanSeeTableRecords([$agencyTier])
        ->assertCanNotSeeTableRecords([$packageTier])
        ->resetTableFilters()
        ->filterTable('discount_type', 'fixed')
        ->assertCanSeeTableRecords([$agencyTier])
        ->assertCanNotSeeTableRecords([$packageTier]);
});
