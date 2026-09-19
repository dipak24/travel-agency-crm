<?php

use App\Http\Middleware\ResolveTenant;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;

uses(RefreshDatabase::class);

test('tenant-owned queries cannot cross tenant boundaries', function () {
    $firstTenant = Tenant::query()->create(['name' => 'First Agency', 'slug' => 'first-agency']);
    $secondTenant = Tenant::query()->create(['name' => 'Second Agency', 'slug' => 'second-agency']);

    app(TenantContext::class)->set($firstTenant);
    TenantUser::query()->create([
        'name' => 'First Staff',
        'email' => 'staff@example.com',
        'password' => 'password',
    ]);

    app(TenantContext::class)->set($secondTenant);

    expect(TenantUser::query()->count())->toBe(0);
    expect(TenantUser::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('tenant-owned records cannot be created without a tenant context', function () {
    expect(fn () => TenantUser::query()->create([
        'name' => 'Unscoped Staff',
        'email' => 'unscoped@example.com',
        'password' => 'password',
    ]))->toThrow(LogicException::class);
});

test('ResolveTenant keeps the tenant context set when replayed through an isolated pipeline, as Livewire does for its persistent middleware', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    app(TenantContext::class)->clear();

    test()->actingAs($customer, 'customer');

    // Livewire re-runs persistent middleware (ResolveTenant among them) against a
    // throwaway request via a Pipeline that terminates immediately (see
    // Livewire\Drawer\Utils::applyMiddleware()) before the real Livewire component
    // method executes — unlike a real request, $next() here does not chain through
    // to the rest of the app. A middleware that cleared its state in a `finally`
    // block around $next() would wipe the tenant context right here, before the
    // component method that actually needs it ever runs.
    (new Pipeline(app()))
        ->send(Request::create('/portal/buy-gift-voucher'))
        ->through([ResolveTenant::class])
        ->then(fn () => new Response);

    expect(app(TenantContext::class)->id())->toBe($tenant->id);
});
