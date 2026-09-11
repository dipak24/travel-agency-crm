<?php

use App\Filament\Tenant\Resources\BookingResource\Pages\EditBooking;
use App\Filament\Tenant\Resources\BookingResource\RelationManagers\PaymentsRelationManager as BookingPaymentsRelationManager;
use App\Filament\Tenant\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Tenant\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Tenant\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Tenant\Resources\InvoiceResource\RelationManagers\ItemsRelationManager;
use App\Filament\Tenant\Resources\PaymentResource\Pages\CreatePayment;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\InvoiceEmailed;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
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

test('a tenant staff member can download an invoice as a PDF', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);

    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alpine Escape']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 150000,
        'tax' => 12000,
        'discount' => 5000,
        'total' => 155000,
        'currency' => 'USD',
        'status' => 'issued',
        'due_date' => now()->addDays(14)->toDateString(),
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListInvoices::class)
        ->callAction(TestAction::make('downloadPdf')->table($invoice))
        ->assertFileDownloaded("{$invoice->invoice_no}.pdf");
});

test('a tenant staff member can email an invoice PDF to the customer', function () {
    Notification::fake();

    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);

    $customer = Customer::factory()->create(['email' => 'traveler@example.com']);
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alpine Escape']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 150000,
        'total' => 150000,
        'currency' => 'USD',
        'status' => 'issued',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListInvoices::class)
        ->callAction(TestAction::make('emailInvoice')->table($invoice));

    Notification::assertSentTo($customer, InvoiceEmailed::class, fn (InvoiceEmailed $notification): bool => $notification->invoice->is($invoice));
});

test('a tenant staff member can create a new guest customer inline while invoicing a booking', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);

    $existingCustomer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $existingCustomer->id, 'trip_name' => 'Alpine Escape']);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreateInvoice::class)
        ->set('data.booking_id', $booking->id)
        ->mountAction(TestAction::make('createOption')->schemaComponent('customer_id'))
        ->set('mountedActions.0.data.name', 'Walk-in Guest')
        ->set('mountedActions.0.data.email', 'guest@example.test')
        ->set('mountedActions.0.data.phone', '555-0100')
        ->set('mountedActions.0.data.type', 'individual')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $guest = Customer::query()->where('email', 'guest@example.test')->first();

    expect($guest)->not->toBeNull()
        ->and($guest->tenant_id)->toBe($tenant->id);
});

test('the invoice policy hides the guest quick-create button from staff who cannot create customers', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $accountant = TenantUser::factory()->create();
    $accountantRole = Role::query()->where('name', 'Accountant')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $accountant->assignRole($accountantRole);

    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alpine Escape']);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($accountant, 'tenant')->test(CreateInvoice::class)
        ->set('data.booking_id', $booking->id)
        ->assertActionHidden(TestAction::make('createOption')->schemaComponent('customer_id'));
});

test('a tenant owner can create an invoice from a booking and record a payment against it', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);

    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alpine Escape']);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreateInvoice::class)
        ->set('data.booking_id', $booking->id)
        ->set('data.amount', 100000)
        ->set('data.total', 100000)
        ->set('data.status', 'issued')
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->where('booking_id', $booking->id)->firstOrFail();
    expect($invoice->invoice_no)->not->toBeEmpty();

    Livewire::actingAs($owner, 'tenant')->test(CreatePayment::class)
        ->set('data.invoice_id', $invoice->id)
        ->set('data.method', 'bank_transfer')
        ->call('create')
        ->assertHasNoFormErrors();

    expect($invoice->fresh())
        ->paidAmount()->toBe(100000)
        ->status->toBe('paid');
});

test('an accountant can create an invoice from a booking and record a payment against it', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $accountant = TenantUser::factory()->create();
    $accountantRole = Role::query()->where('name', 'Accountant')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $accountant->assignRole($accountantRole);

    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alpine Escape']);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($accountant, 'tenant')->test(CreateInvoice::class)
        ->set('data.booking_id', $booking->id)
        ->set('data.amount', 50000)
        ->set('data.total', 50000)
        ->set('data.status', 'issued')
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->where('booking_id', $booking->id)->firstOrFail();

    Livewire::actingAs($accountant, 'tenant')->test(CreatePayment::class)
        ->set('data.invoice_id', $invoice->id)
        ->set('data.method', 'cash')
        ->call('create')
        ->assertHasNoFormErrors();

    expect($invoice->fresh())
        ->paidAmount()->toBe(50000)
        ->status->toBe('paid');
});

test('a tenant staff member can add a line item to an invoice from its nested relation manager', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);

    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alpine Escape']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'total' => 0,
        'currency' => 'USD',
        'status' => 'draft',
    ]);
    $item = $invoice->items()->create([
        'description' => 'Airport transfer',
        'qty' => 2,
        'unit_price' => 1500,
        'total' => 3000,
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ItemsRelationManager::class, [
        'ownerRecord' => $invoice,
        'pageClass' => EditInvoice::class,
    ])
        ->assertOk()
        ->assertActionExists(TestAction::make('create')->table())
        ->assertCanSeeTableRecords([$item]);

    expect($item->tenant_id)->toBe($tenant->id);
});

test('a booking surfaces payments across all of its invoices via its nested relation manager', function () {
    $tenant = billingTenant('Northwind Travel', 'northwind-travel');
    $this->seed();

    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
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
    Payment::query()->create([
        'invoice_id' => $invoice->id, 'amount' => 2000, 'currency' => 'USD',
        'method' => 'bank_transfer', 'type' => 'installment', 'status' => 'completed', 'paid_at' => now(),
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(BookingPaymentsRelationManager::class, [
        'ownerRecord' => $booking,
        'pageClass' => EditBooking::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($booking->payments);
});
