<?php

use App\Filament\Resources\SubscriptionPlanResource\Pages\CreateSubscriptionPlan;
use App\Filament\Resources\TenantInvoiceResource\Pages\CreateTenantInvoice;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * fillForm() doesn't persist state on this project's exact Filament/Livewire
 * combination — see .ai/rules/feature.md. Route around it with direct set().
 */
function fillBillingForm(Testable $test, array $data): Testable
{
    foreach ($data as $key => $value) {
        $test->set("data.{$key}", $value);
    }

    return $test;
}

test('seeded platform admin can access the billing resource pages', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    $this->actingAs($admin, 'super_admin')->get('/admin/subscription-plans')->assertOk();
    $this->actingAs($admin, 'super_admin')->get('/admin/subscription-plans/create')->assertOk();
    $this->actingAs($admin, 'super_admin')->get('/admin/tenant-invoices')->assertOk();
    $this->actingAs($admin, 'super_admin')->get('/admin/tenant-invoices/create')->assertOk();
    $this->actingAs($admin, 'super_admin')->get('/admin/tenant-payments')->assertOk();
});

test('a platform admin without the manage billing permission is denied access', function () {
    $this->seed();

    $unprivileged = SuperAdmin::factory()->create();

    expect(Gate::forUser($unprivileged)->allows('viewAny', SubscriptionPlan::class))->toBeFalse()
        ->and(Gate::forUser($unprivileged)->allows('viewAny', TenantInvoice::class))->toBeFalse()
        ->and(Gate::forUser($unprivileged)->allows('viewAny', TenantPayment::class))->toBeFalse();
});

test('a platform admin can create a subscription plan', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateSubscriptionPlan::class);

    fillBillingForm($test, [
        'name' => 'Growth',
        'price' => 9900,
        'billing_cycle' => 'monthly',
        'is_active' => true,
    ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SubscriptionPlan::query()->where('name', 'Growth')->exists())->toBeTrue();
});

test('a platform admin can create a tenant invoice with line items and a unique invoice number', function () {
    $this->seed();

    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $tenant = Tenant::factory()->create();

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateTenantInvoice::class);

    fillBillingForm($test, [
        'tenant_id' => $tenant->getKey(),
        'status' => 'draft',
        'currency' => 'USD',
        'issue_date' => now()->toDateString(),
        'tax' => 0,
        'discount' => 0,
        'items' => [
            ['type' => 'subscription', 'description' => 'Growth plan — September', 'qty' => 1, 'unit_price' => 4900],
            ['type' => 'hosting', 'description' => 'Hosting — September', 'qty' => 1, 'unit_price' => 1000],
            ['type' => 'domain', 'description' => 'Domain renewal', 'qty' => 1, 'unit_price' => 1500],
        ],
    ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = TenantInvoice::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();

    expect($invoice->items()->withoutGlobalScopes()->count())->toBe(3)
        ->and($invoice->subtotal)->toBe(7400)
        ->and($invoice->total)->toBe(7400)
        ->and($invoice->invoice_no)->toStartWith('PB-');

    // A second invoice for the same tenant in the same year gets the next sequence.
    $tenantContext = app(TenantContext::class);
    $tenantContext->set($tenant);
    $second = TenantInvoice::query()->create(['status' => 'draft', 'currency' => 'USD']);
    $tenantContext->clear();

    expect($second->invoice_no)->not->toBe($invoice->invoice_no);
});

test('recording partial then full platform payments moves a tenant invoice through issued, partially_paid, and paid', function () {
    $this->seed();

    $tenant = Tenant::factory()->create();
    $tenantContext = app(TenantContext::class);
    $tenantContext->set($tenant);

    $invoice = TenantInvoice::query()->create([
        'status' => 'issued',
        'currency' => 'USD',
        'total' => 5000,
    ]);

    expect($invoice->paidAmount())->toBe(0)
        ->and($invoice->balanceDue())->toBe(5000);

    TenantPayment::query()->create([
        'tenant_invoice_id' => $invoice->id, 'amount' => 2000, 'currency' => 'USD',
        'method' => 'bank_transfer', 'type' => 'installment', 'status' => 'completed', 'paid_at' => now(),
    ]);
    $invoice->refresh();

    expect($invoice->status)->toBe('partially_paid')
        ->and($invoice->balanceDue())->toBe(3000);

    TenantPayment::query()->create([
        'tenant_invoice_id' => $invoice->id, 'amount' => 2000, 'currency' => 'USD',
        'method' => 'bank_transfer', 'type' => 'installment', 'status' => 'completed', 'paid_at' => now(),
    ]);
    $invoice->refresh();

    expect($invoice->status)->toBe('partially_paid')
        ->and($invoice->balanceDue())->toBe(1000);

    TenantPayment::query()->create([
        'tenant_invoice_id' => $invoice->id, 'amount' => 1000, 'currency' => 'USD',
        'method' => 'bank_transfer', 'type' => 'final', 'status' => 'completed', 'paid_at' => now(),
    ]);
    $invoice->refresh();

    expect($invoice->status)->toBe('paid')
        ->and($invoice->paidAmount())->toBe(5000)
        ->and($invoice->balanceDue())->toBe(0);

    $tenantContext->clear();
});

test('tenant invoices are only visible from the admin panel via withoutGlobalScopes across every tenant', function () {
    $this->seed();

    $tenantContext = app(TenantContext::class);

    $tenantA = Tenant::factory()->create();
    $tenantContext->set($tenantA);
    TenantInvoice::query()->create(['status' => 'draft', 'currency' => 'USD']);

    $tenantB = Tenant::factory()->create();
    $tenantContext->set($tenantB);
    TenantInvoice::query()->create(['status' => 'draft', 'currency' => 'USD']);

    $tenantContext->clear();

    expect(TenantInvoice::query()->count())->toBe(0)
        ->and(TenantInvoice::query()->withoutGlobalScopes()->count())->toBe(2);
});
