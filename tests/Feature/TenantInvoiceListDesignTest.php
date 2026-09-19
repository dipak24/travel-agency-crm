<?php

use App\Filament\Tenant\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Tenant\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * PermissionSeeder must run AFTER the tenant exists (it only seeds permissions/roles for tenants
 * already in the database — see PermissionSeeder::run()) and BEFORE any role is assigned, or
 * InvoicePolicy::canManage()'s ungated hasPermissionTo('view invoices') throws
 * PermissionDoesNotExist rather than falling back to a role check.
 */
function tenantInvoiceDesignTenant(string $name, string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => $name, 'slug' => $slug]);
    (new PermissionSeeder)->run();

    return $tenant;
}

function tenantInvoiceDesignOwner(Tenant $tenant): TenantUser
{
    $owner = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $role = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($role);

    return $owner;
}

function tenantDesignInvoiceFor(Customer $customer, array $overrides = []): Invoice
{
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);

    return Invoice::query()->create(array_merge([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 100000,
        'total' => 100000,
        'currency' => 'USD',
        'status' => 'issued',
    ], $overrides));
}

test('the invoices list defaults to the Outstanding tab and a Paid tab shows only paid invoices', function () {
    $tenant = tenantInvoiceDesignTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tenantInvoiceDesignOwner($tenant);
    $customer = Customer::factory()->create();
    $outstanding = tenantDesignInvoiceFor($customer, ['status' => 'issued']);
    $paid = tenantDesignInvoiceFor($customer, ['status' => 'paid']);

    Filament::setCurrentPanel('tenant');

    $test = Livewire::actingAs($owner, 'tenant')->test(ListInvoices::class);

    $test->assertCanSeeTableRecords([$outstanding])
        ->assertCanNotSeeTableRecords([$paid]);

    $test->set('activeTab', 'paid')
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$outstanding]);
});

test('the invoices list can be filtered by status and by transaction type', function () {
    $tenant = tenantInvoiceDesignTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tenantInvoiceDesignOwner($tenant);
    $customer = Customer::factory()->create();
    $standard = tenantDesignInvoiceFor($customer, ['status' => 'overdue']);
    $giftVoucher = tenantDesignInvoiceFor($customer, ['status' => 'overdue', 'purpose' => 'gift_voucher_purchase', 'booking_id' => null]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListInvoices::class)
        ->set('activeTab', 'outstanding')
        ->filterTable('status', 'overdue')
        ->assertCanSeeTableRecords([$standard, $giftVoucher])
        ->filterTable('purpose', 'gift_voucher_purchase')
        ->assertCanSeeTableRecords([$giftVoucher])
        ->assertCanNotSeeTableRecords([$standard]);
});

test('the Mode column shows the latest completed payment method, or a dash when nothing has been paid', function () {
    $tenant = tenantInvoiceDesignTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tenantInvoiceDesignOwner($tenant);
    $customer = Customer::factory()->create();
    $unpaid = tenantDesignInvoiceFor($customer);
    $paidByCard = tenantDesignInvoiceFor($customer, ['status' => 'paid']);
    $paidByCard->payments()->create([
        'amount' => 100000, 'currency' => 'USD', 'method' => 'card', 'type' => 'installment',
        'status' => 'completed', 'paid_at' => now(),
    ]);

    expect($unpaid->latestPaymentMethod())->toBeNull()
        ->and($paidByCard->latestPaymentMethod())->toBe('card');

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListInvoices::class)
        ->set('activeTab', 'paid')
        ->assertSeeText('Card');
});

test('the tenant invoice view shows the order summary breakdown and the "From" customer card', function () {
    $tenant = tenantInvoiceDesignTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tenantInvoiceDesignOwner($tenant);
    $customer = Customer::factory()->create(['name' => 'Jane Traveler', 'email' => 'jane@example.com']);
    $invoice = tenantDesignInvoiceFor($customer, ['amount' => 100000, 'discount' => 10000, 'total' => 90000]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSeeText('Jane Traveler')
        ->assertSeeText('jane@example.com')
        ->assertSeeText('Order summary')
        ->assertSeeText('$1,000.00') // subtotal
        ->assertSeeText('$900.00'); // total after the $100 discount
});

test('the gift voucher purchase panel on the tenant invoice view shows the purchaser, not the recipient', function () {
    $tenant = tenantInvoiceDesignTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = tenantInvoiceDesignOwner($tenant);
    $customer = Customer::factory()->create();
    $invoice = tenantDesignInvoiceFor($customer, [
        'purpose' => 'gift_voucher_purchase',
        'booking_id' => null,
        'purchaser_first_name' => 'Jane',
        'purchaser_last_name' => 'Buyer',
        'purchaser_email' => 'jane.buyer@example.com',
        'purchaser_phone' => '555-0100',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSeeText('Gift voucher purchase')
        ->assertSeeText('Jane Buyer')
        ->assertSeeText('jane.buyer@example.com');
});
