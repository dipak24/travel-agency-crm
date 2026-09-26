<?php

use App\Enums\CustomerStatus;
use App\Filament\Tenant\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\CustomerAccountLink;
use App\Notifications\PasswordResetRequested;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
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

test('a setup link activates and verifies a pending customer, and its token cannot be reused', function () {
    $tenant = portalTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);
    $customer = Customer::factory()->pending()->create();

    Notification::fake();
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListCustomers::class)
        ->callAction(TestAction::make('sendAccessLink')->table($customer))
        ->assertHasNoActionErrors();

    Notification::assertSentTo($customer, CustomerAccountLink::class, fn (CustomerAccountLink $notification): bool => $notification->isInvite);

    $sent = Notification::sent($customer, CustomerAccountLink::class)->first();
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

    $customer->refresh();

    expect($customer->password)->not->toBeNull()
        ->and($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->email_verified_at)->not->toBeNull()
        ->and(Password::broker('customers')->tokenExists($customer, $credentials['token']))->toBeFalse()
        ->and(auth('customer')->attempt(['email' => $customer->email, 'password' => 'a-new-password']))->toBeTrue();
});

test('a customer who already has a password is sent a reset link rather than an invite', function () {
    $tenant = portalTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);
    $customer = Customer::factory()->create(['password' => 'already-set']);

    Filament::setCurrentPanel('tenant');

    Notification::fake();

    Livewire::actingAs($owner, 'tenant')->test(ListCustomers::class)
        ->callAction(TestAction::make('sendAccessLink')->table($customer));

    Notification::assertSentTo($customer, CustomerAccountLink::class, fn (CustomerAccountLink $notification): bool => ! $notification->isInvite);
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

    Notification::assertSentTo($customer, PasswordResetRequested::class);
});
