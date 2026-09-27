<?php

use App\Filament\Pages\SystemSettings;
use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Models\PlatformSetting;
use App\Models\SuperAdmin;
use App\Models\TenantUser;
use App\Support\Currencies;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function systemSettingsAdmin(): SuperAdmin
{
    return SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
}

function turnOnMaintenance(?string $message = 'Upgrading servers until 10:00 UTC.'): void
{
    PlatformSetting::query()->create([
        'currencies' => Currencies::DEFAULT_ENABLED,
        'default_currency' => 'USD',
        'maintenance_mode' => true,
        'maintenance_message' => $message,
    ]);
}

test('a super admin can save enabled currencies, the default currency and maintenance mode', function () {
    $this->seed();
    Filament::setCurrentPanel('admin');

    Livewire::actingAs(systemSettingsAdmin(), 'super_admin')->test(SystemSettings::class)
        ->assertSet('data.currencies', Currencies::DEFAULT_ENABLED)
        ->set('data.currencies', ['USD', 'NPR', 'THB'])
        ->set('data.default_currency', 'NPR')
        ->set('data.maintenance_mode', true)
        ->set('data.maintenance_message', 'Back at noon.')
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = PlatformSetting::query()->sole();

    expect($settings->currencies)->toBe(['USD', 'NPR', 'THB'])
        ->and($settings->default_currency)->toBe('NPR')
        ->and($settings->maintenance_mode)->toBeTrue()
        ->and($settings->maintenance_message)->toBe('Back at noon.');
});

test('the default currency must be one of the enabled currencies', function () {
    $this->seed();
    Filament::setCurrentPanel('admin');

    Livewire::actingAs(systemSettingsAdmin(), 'super_admin')->test(SystemSettings::class)
        ->set('data.currencies', ['USD', 'EUR'])
        ->set('data.default_currency', 'NPR')
        ->call('save')
        ->assertHasFormErrors(['default_currency' => 'The default currency must be one of the enabled currencies.']);

    expect(PlatformSetting::query()->exists())->toBeFalse();
});

test('a platform staff member without the manage platform permission cannot access system settings', function () {
    $this->seed();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $staff = SuperAdmin::factory()->create();
    $staff->assignRole(Role::create(['name' => 'Support', 'guard_name' => 'super_admin', 'team_id' => 0]));

    $this->actingAs($staff, 'super_admin');

    expect(SystemSettings::canAccess())->toBeFalse();
});

test('tenant currency choices follow the enabled currencies but keep a record\'s current one', function () {
    PlatformSetting::query()->create(['currencies' => ['USD', 'THB'], 'default_currency' => 'THB']);

    expect(Currencies::options())->toBe(['USD' => 'USD - US Dollar', 'THB' => 'THB - Thai Baht'])
        ->and(array_keys(Currencies::options('NPR')))->toBe(['USD', 'THB', 'NPR'])
        ->and(Currencies::defaultCode())->toBe('THB');
});

test('a new tenant defaults to the platform default currency', function () {
    $this->seed();
    PlatformSetting::query()->create(['currencies' => ['USD', 'NPR'], 'default_currency' => 'NPR']);
    Filament::setCurrentPanel('admin');

    Livewire::actingAs(systemSettingsAdmin(), 'super_admin')->test(CreateTenant::class)
        ->assertSet('data.currency', 'NPR');
});

test('maintenance mode shows the message on the staff panel with a 503', function () {
    $this->seed();
    turnOnMaintenance();

    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $this->actingAsStaff($owner)->get('/tenant')
        ->assertServiceUnavailable()
        ->assertSee('Upgrading servers until 10:00 UTC.');
});

test('maintenance mode answers the public API with a JSON 503', function () {
    $this->seed();
    turnOnMaintenance(null);

    $this->getJson('/api/v1/packages?tenant=demo-travel')
        ->assertServiceUnavailable()
        ->assertJson(['message' => 'We are carrying out scheduled maintenance and will be back shortly.']);
});

test('maintenance mode leaves the admin panel available', function () {
    $this->seed();
    turnOnMaintenance();

    $this->actingAs(systemSettingsAdmin(), 'super_admin')
        ->get(config('app.url').'/admin/system-settings')
        ->assertOk();
});
