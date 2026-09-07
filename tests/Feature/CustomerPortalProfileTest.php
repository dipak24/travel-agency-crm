<?php

use App\Filament\Portal\Pages\Profile;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function profileTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

test('a customer can view and reach the portal profile page', function () {
    $tenant = profileTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);

    $this->actingAs($customer, 'customer')->get('/portal/profile')->assertOk();
});

test('a customer can update their contact details, passport number, and avatar', function () {
    Storage::fake('public');
    $tenant = profileTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create([
        'password' => 'secret-password',
        'passport_no' => 'P1111111',
    ]);

    Filament::setCurrentPanel('portal');
    $avatar = UploadedFile::fake()->image('avatar.jpg');

    Livewire::actingAs($customer, 'customer')->test(Profile::class)
        ->assertSet('data.passport_no', 'P1111111')
        ->set('data.phone', '555-0199')
        ->set('data.nationality', 'Nepali')
        ->set('data.passport_no', 'P2222222')
        ->set('data.avatar', $avatar)
        ->call('save')
        ->assertHasNoFormErrors();

    $customer->refresh();

    expect($customer->phone)->toBe('555-0199')
        ->and($customer->nationality)->toBe('Nepali')
        ->and($customer->passport_no)->toBe('P2222222')
        ->and($customer->avatar)->not->toBeNull();

    Storage::disk('public')->assertExists($customer->avatar);
    expect($customer->getFilamentAvatarUrl())->toContain($customer->avatar);
});

test('a customer cannot change their email to one already used by another customer in the same tenant', function () {
    $tenant = profileTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $existing = Customer::factory()->create(['email' => 'taken@example.test']);
    $customer = Customer::factory()->create(['password' => 'secret-password']);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(Profile::class)
        ->set('data.email', 'taken@example.test')
        ->call('save')
        ->assertHasFormErrors(['email']);
});

test('a customer can reuse an email already used by a customer of a different tenant', function () {
    $otherTenant = profileTenant('Blue Peak Journeys', 'blue-peak-journeys');
    app(TenantContext::class)->set($otherTenant);
    Customer::factory()->create(['email' => 'shared@example.test']);

    $tenant = profileTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(Profile::class)
        ->set('data.email', 'shared@example.test')
        ->set('data.currentPassword', 'secret-password')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->fresh()->email)->toBe('shared@example.test');
});
