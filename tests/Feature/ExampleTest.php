<?php

use App\Models\Customer;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the application returns a successful response', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
});

test('tenant and customer panels expose dedicated login pages', function () {
    $this->get('/tenant/login')->assertSuccessful();
    $this->get('/portal/login')->assertSuccessful();
});

test('each guard accepts valid credentials and rejects invalid credentials', function () {
    $tenant = Tenant::query()->create(['name' => 'Travel Agency', 'slug' => 'travel-agency']);

    SuperAdmin::query()->create([
        'name' => 'Platform Admin',
        'email' => 'admin@example.com',
        'password' => 'password',
        'status' => 'active',
    ]);

    app(TenantContext::class)->set($tenant);

    TenantUser::query()->create([
        'name' => 'Travel Staff',
        'email' => 'staff@example.com',
        'password' => 'password',
        'status' => 'active',
    ]);

    Customer::query()->create([
        'name' => 'Traveler',
        'email' => 'customer@example.com',
        'password' => 'password',
    ]);

    expect(auth('super_admin')->attempt(['email' => 'admin@example.com', 'password' => 'password']))->toBeTrue()
        ->and(auth('super_admin')->attempt(['email' => 'admin@example.com', 'password' => 'wrong']))->toBeFalse()
        ->and(auth('tenant')->attempt(['email' => 'staff@example.com', 'password' => 'password']))->toBeTrue()
        ->and(auth('tenant')->attempt(['email' => 'staff@example.com', 'password' => 'wrong']))->toBeFalse()
        ->and(auth('customer')->attempt(['email' => 'customer@example.com', 'password' => 'password']))->toBeTrue()
        ->and(auth('customer')->attempt(['email' => 'customer@example.com', 'password' => 'wrong']))->toBeFalse();
});

test('authenticated users reach only their own panel dashboard', function () {
    $tenant = Tenant::query()->create(['name' => 'Travel Agency', 'slug' => 'travel-agency']);
    $superAdmin = SuperAdmin::query()->create([
        'name' => 'Platform Admin',
        'email' => 'admin@example.com',
        'password' => 'password',
        'status' => 'active',
    ]);

    app(TenantContext::class)->set($tenant);
    $tenantUser = TenantUser::query()->create([
        'name' => 'Travel Staff',
        'email' => 'staff@example.com',
        'password' => 'password',
        'status' => 'active',
    ]);
    $customer = Customer::query()->create([
        'name' => 'Traveler',
        'email' => 'customer@example.com',
        'password' => 'password',
    ]);

    $this->actingAs($superAdmin, 'super_admin')->get('/admin')->assertSuccessful();
    $this->actingAs($tenantUser, 'tenant')->get('/tenant')->assertSuccessful();
    $this->actingAs($customer, 'customer')->get('/portal')->assertSuccessful();
});
