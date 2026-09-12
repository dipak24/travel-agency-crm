<?php

use App\Filament\Tenant\Pages\MailSettings;
use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Models\TenantUser;
use App\Notifications\TestMailSettingNotification;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function tmsTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function tmsOwner(Tenant $tenant): TenantUser
{
    $owner = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $role = Role::firstOrCreate(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]);
    $owner->assignRole($role);

    return $owner;
}

test('a tenant owner can save their own SMTP settings', function () {
    $tenant = tmsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tmsOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(MailSettings::class)
        ->set('data.enabled', true)
        ->set('data.from_name', 'Northwind Support')
        ->set('data.from_address', 'support@northwind.example')
        ->set('data.host', 'smtp.northwind.example')
        ->set('data.port', 587)
        ->set('data.username', 'smtp-user')
        ->set('data.password', 'smtp-pass')
        ->set('data.encryption', 'tls')
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = TenantMailSetting::query()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($settings->enabled)->toBeTrue()
        ->and($settings->from_address)->toBe('support@northwind.example')
        ->and($settings->credentials['host'])->toBe('smtp.northwind.example')
        ->and($settings->credentials['password'])->toBe('smtp-pass');
});

test('enabling SMTP without required credentials is rejected with validation errors', function () {
    $tenant = tmsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tmsOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(MailSettings::class)
        ->set('data.enabled', true)
        ->set('data.from_name', '')
        ->set('data.from_address', '')
        ->set('data.port', '')
        ->call('save')
        ->assertHasFormErrors(['from_name', 'from_address', 'host', 'port', 'username', 'password']);

    expect(TenantMailSetting::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a disabled state does not require SMTP fields to be filled', function () {
    $tenant = tmsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tmsOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(MailSettings::class)
        ->set('data.enabled', false)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(TenantMailSetting::query()->where('tenant_id', $tenant->id)->firstOrFail()->enabled)->toBeFalse();
});

test('saved SMTP settings are reloaded correctly on the next visit', function () {
    $tenant = tmsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tmsOwner($tenant);

    TenantMailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
        'from_name' => 'Northwind Support',
        'from_address' => 'support@northwind.example',
        'credentials' => [
            'host' => 'smtp.northwind.example', 'port' => 587,
            'username' => 'smtp-user', 'password' => 'smtp-pass', 'encryption' => 'tls',
        ],
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(MailSettings::class)
        ->assertSet('data.enabled', true)
        ->assertSet('data.from_address', 'support@northwind.example')
        ->assertSet('data.host', 'smtp.northwind.example');
});

test('a staff member without the manage settings permission cannot access mail settings', function () {
    $tenant = tmsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $staff = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $role = Role::create(['name' => 'Sales Agent', 'guard_name' => 'tenant', 'team_id' => $tenant->id]);
    $staff->assignRole($role);

    $this->actingAs($staff, 'tenant');

    expect(MailSettings::canAccess())->toBeFalse();
});

test('sending a test email is rejected until settings are saved and enabled', function () {
    Notification::fake();

    $tenant = tmsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tmsOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(MailSettings::class)
        ->call('sendTestEmail');

    Notification::assertNotSentTo($owner, TestMailSettingNotification::class);
});

test('sending a test email notifies the logged-in tenant user once settings are enabled', function () {
    Notification::fake();

    $tenant = tmsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tmsOwner($tenant);

    TenantMailSetting::query()->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
        'from_name' => 'Northwind Support',
        'from_address' => 'support@northwind.example',
        'credentials' => [
            'host' => 'smtp.northwind.example', 'port' => 587,
            'username' => 'smtp-user', 'password' => 'smtp-pass', 'encryption' => 'tls',
        ],
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(MailSettings::class)
        ->call('sendTestEmail');

    Notification::assertSentTo($owner, TestMailSettingNotification::class);
});
