<?php

use App\Filament\Tenant\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\CustomerPortalInvite;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function portalTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function extractResetCredentials(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return ['email' => $query['email'], 'token' => $query['token']];
}

test('a customer with no password yet cannot log in', function () {
    $tenant = portalTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => null]);

    expect(auth('customer')->attempt(['email' => $customer->email, 'password' => 'anything']))->toBeFalse();
});

test('canAccessPanel no longer depends on the customer already having a password', function () {
    $tenant = portalTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => null]);

    expect($customer->canAccessPanel(Filament::getPanel('portal')))->toBeTrue();

    $tenant->update(['status' => 'suspended']);
    $customer->refresh();

    expect($customer->canAccessPanel(Filament::getPanel('portal')))->toBeFalse();
});

test('a tenant owner can invite a customer to the portal, and the customer can complete setup and log in', function () {
    $tenant = portalTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);
    $customer = Customer::factory()->create(['password' => null]);

    Notification::fake();
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListCustomers::class)
        ->callAction(TestAction::make('inviteToPortal')->table($customer))
        ->assertHasNoActionErrors();

    Notification::assertSentTo($customer, CustomerPortalInvite::class);

    $sent = Notification::sent($customer, CustomerPortalInvite::class)->first();
    $credentials = extractResetCredentials($sent->url);

    expect($credentials['email'])->toBe($customer->email);

    Filament::setCurrentPanel('portal');

    Livewire::test(ResetPassword::class, [
        'email' => $credentials['email'],
        'token' => $credentials['token'],
    ])
        ->set('password', 'a-new-password')
        ->set('passwordConfirmation', 'a-new-password')
        ->call('resetPassword');

    expect($customer->fresh()->password)->not->toBeNull()
        ->and(auth('customer')->attempt(['email' => $customer->email, 'password' => 'a-new-password']))->toBeTrue();
});

test('the invite action is hidden once a customer already has a portal password', function () {
    $tenant = portalTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);
    $customer = Customer::factory()->create(['password' => 'already-set']);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListCustomers::class)
        ->assertActionHidden(TestAction::make('inviteToPortal')->table($customer));
});

test('a customer who already has a password can use the portal forgot-password page', function () {
    $tenant = portalTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'already-set']);

    Notification::fake();
    Filament::setCurrentPanel('portal');

    Livewire::test(RequestPasswordReset::class)
        ->set('data.email', $customer->email)
        ->call('request');

    Notification::assertSentTo($customer, FilamentResetPasswordNotification::class);
});
