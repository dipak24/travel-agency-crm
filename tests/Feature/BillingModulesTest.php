<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
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

test('tenant owner can access the payments resource pages', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    // Full HTTP page loads render the whole panel nav, which checks every
    // resource's policy — those need real permission rows seeded, or the
    // (only ever policy-level-tested) defensive fallback doesn't cover every
    // resource in the panel. Matches AdminTenantManagementTest's convention.
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);
    app(TenantContext::class)->clear();

    $this->actingAs($owner, 'tenant')->get('/tenant/payments')->assertOk();
    $this->actingAs($owner, 'tenant')->get('/tenant/payments/create')->assertOk();
});

test('recording partial then full payments moves an invoice through issued, partially_paid, and paid', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');

    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alpine Escape']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'total' => 5000,
        'currency' => 'USD',
        'status' => 'issued',
    ]);

    expect($invoice->paidAmount())->toBe(0)
        ->and($invoice->balanceDue())->toBe(5000);

    Payment::query()->create([
        'invoice_id' => $invoice->id, 'amount' => 2000, 'currency' => 'USD',
        'method' => 'bank_transfer', 'type' => 'installment', 'status' => 'completed', 'paid_at' => now(),
    ]);
    $invoice->refresh();

    expect($invoice->status)->toBe('partially_paid')
        ->and($invoice->paidAmount())->toBe(2000)
        ->and($invoice->balanceDue())->toBe(3000);

    Payment::query()->create([
        'invoice_id' => $invoice->id, 'amount' => 2000, 'currency' => 'USD',
        'method' => 'bank_transfer', 'type' => 'installment', 'status' => 'completed', 'paid_at' => now(),
    ]);
    $invoice->refresh();

    expect($invoice->status)->toBe('partially_paid')
        ->and($invoice->paidAmount())->toBe(4000)
        ->and($invoice->balanceDue())->toBe(1000);

    Payment::query()->create([
        'invoice_id' => $invoice->id, 'amount' => 1000, 'currency' => 'USD',
        'method' => 'bank_transfer', 'type' => 'final', 'status' => 'completed', 'paid_at' => now(),
    ]);
    $invoice->refresh();

    expect($invoice->status)->toBe('paid')
        ->and($invoice->paidAmount())->toBe(5000)
        ->and($invoice->balanceDue())->toBe(0);

    // A refund pushes a fully paid invoice back out of "paid".
    Payment::query()->create([
        'invoice_id' => $invoice->id, 'amount' => 1000, 'currency' => 'USD',
        'method' => 'bank_transfer', 'type' => 'refund', 'status' => 'completed', 'paid_at' => now(),
    ]);
    $invoice->refresh();

    expect($invoice->status)->toBe('partially_paid')
        ->and($invoice->paidAmount())->toBe(4000)
        ->and($invoice->balanceDue())->toBe(1000);
});

test('payment policy restricts access to same-tenant staff', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $otherTenant = billingTenant('Blue Peak Journeys', 'blue-peak-journeys');

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::create(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]);
    $owner->assignRole($ownerRole);

    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alpine Escape']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'total' => 5000,
        'currency' => 'USD',
        'status' => 'issued',
    ]);
    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id, 'amount' => 2000, 'currency' => 'USD',
        'method' => 'cash', 'status' => 'completed',
    ]);

    app(TenantContext::class)->set($otherTenant);
    $otherUser = TenantUser::factory()->create();
    app(TenantContext::class)->set($tenant);

    expect((new PaymentPolicy)->view($owner, $payment))->toBeTrue()
        ->and((new PaymentPolicy)->view($otherUser, $payment))->toBeFalse();
});
