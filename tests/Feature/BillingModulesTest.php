<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Policies\InvoicePolicy;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function billingTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

test('invoices are tenant-scoped and generate unique invoice numbers', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $otherTenant = billingTenant('Blue Peak Journeys', 'blue-peak-journeys');

    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create([
        'customer_id' => $customer->id,
        'trip_name' => 'Alpine Escape',
    ]);

    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 150000,
        'tax' => 12000,
        'discount' => 5000,
        'total' => 155000,
        'currency' => 'USD',
        'status' => 'draft',
        'due_date' => now()->addDays(14)->toDateString(),
    ]);

    $expectedPrefix = strtoupper(Str::slug($tenant->slug, ''));
    $expectedInvoiceNumber = sprintf('%s-%s-%04d', $expectedPrefix, now()->year, 1);

    expect($invoice->invoice_no)->toBe($expectedInvoiceNumber)
        ->and(Invoice::query()->withoutGlobalScopes()->find($invoice->id)->tenant_id)->toBe($tenant->id)
        ->and(Invoice::query()->find($invoice->id))->not->toBeNull();

    app(TenantContext::class)->set($otherTenant);

    expect(Invoice::query()->find($invoice->id))->toBeNull();
});

test('invoice policy restricts access to same-tenant staff', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $otherTenant = billingTenant('Blue Peak Journeys', 'blue-peak-journeys');

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::create([
        'name' => 'Tenant Owner',
        'guard_name' => 'tenant',
        'team_id' => $tenant->id,
    ]);
    $owner->assignRole($ownerRole);

    $customer = Customer::factory()->create();
    $booking = Booking::query()->create([
        'customer_id' => $customer->id,
        'trip_name' => 'Alpine Escape',
    ]);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 150000,
        'tax' => 12000,
        'discount' => 5000,
        'total' => 155000,
        'currency' => 'USD',
        'status' => 'draft',
    ]);

    app(TenantContext::class)->set($otherTenant);
    $otherUser = TenantUser::factory()->create();
    app(TenantContext::class)->set($tenant);

    expect((new InvoicePolicy)->view($owner, $invoice))->toBeTrue()
        ->and((new InvoicePolicy)->view($otherUser, $invoice))->toBeFalse();
});

test('invoice permissions are seeded for the tenant accountant role', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');

    app(TenantContext::class)->set($tenant);
    (new PermissionSeeder)->run();

    $accountant = Role::query()
        ->where('name', 'Accountant')
        ->where('guard_name', 'tenant')
        ->where('team_id', $tenant->id)
        ->first();
    $permissionNames = Permission::query()
        ->where('guard_name', 'tenant')
        ->whereIn('name', ['view invoices', 'create invoices', 'update invoices', 'delete invoices'])
        ->pluck('name')
        ->all();

    expect($accountant)->not->toBeNull()
        ->and($accountant->hasPermissionTo('view invoices'))->toBeTrue()
        ->and($accountant->hasPermissionTo('create invoices'))->toBeTrue()
        ->and($accountant->hasPermissionTo('update invoices'))->toBeTrue()
        ->and($accountant->hasPermissionTo('delete invoices'))->toBeTrue()
        ->and($permissionNames)->toHaveCount(4);
});
