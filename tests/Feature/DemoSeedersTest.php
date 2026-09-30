<?php

use App\Models\Booking;
use App\Models\FixedDeparture;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PublicLeadPage;
use App\Models\Tenant;
use Database\Seeders\DemoOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the demo seeders build a consistent, bookable agency and can be run again', function () {
    $this->seed();
    $this->seed(DemoOperationsSeeder::class);
    $this->seed(DemoOperationsSeeder::class);

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    $bookings = Booking::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()->keyBy('status');
    $invoices = Invoice::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();

    expect(Package::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('status', 'package_code')->all())
        ->toMatchArray(['EBC-14' => 'published', 'ABC-10' => 'published', 'CHT-3' => 'draft', 'LNG-7' => 'archived'])
        ->and($bookings)->toHaveCount(4)
        ->and($bookings['confirmed']->total_amount)->toBeGreaterThan(0)
        ->and($invoices->firstWhere('booking_id', $bookings['confirmed']->id)->status)->toBe('partially_paid')
        ->and($invoices->firstWhere('booking_id', $bookings['completed']->id)->status)->toBe('paid')
        ->and($invoices->pluck('invoice_no')->unique())->toHaveCount($invoices->count())
        ->and(FixedDeparture::query()->withoutGlobalScopes()->whereKey($bookings['confirmed']->fixed_departure_id)->value('booked_slots'))->toBe(2)
        ->and(PublicLeadPage::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('is_active'))->toBeTrue();

    $this->get(portalUrl($tenant, '/book/EBC-14'))->assertOk()->assertSee('Everest Base Camp Trek');
});
