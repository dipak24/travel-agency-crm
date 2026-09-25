<?php

use App\Models\BookingWaitlist;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\Lead;
use App\Models\Package;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function publicApiTenant(string $slug, array $attributes = []): Tenant
{
    return Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'NPR', ...$attributes]);
}

function publicApiPackage(Tenant $tenant, string $slug, array $attributes = []): Package
{
    return app(TenantContext::class)->wrap($tenant, fn (): Package => Package::query()->create([
        'name' => ucwords(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'package_code' => strtoupper($slug),
        'duration_days' => 5,
        'base_price' => 50000,
        'sales_price' => 80000,
        'status' => 'published',
        'is_public' => true,
        ...$attributes,
    ]));
}

function publicApiDeparture(Package $package, array $attributes = []): FixedDeparture
{
    return app(TenantContext::class)->wrap($package->tenant, fn (): FixedDeparture => FixedDeparture::query()->create([
        'package_id' => $package->id,
        'start_date' => now()->addMonth()->toDateString(),
        'end_date' => now()->addMonth()->addDays(4)->toDateString(),
        'total_slots' => 10,
        ...$attributes,
    ]));
}

/**
 * @return array{name: string, email: string, phone: string}
 */
function publicApiContact(): array
{
    return ['name' => 'Asha Gurung', 'email' => 'asha@example.com', 'phone' => '+9779800000000'];
}

test('requests without a resolvable tenant are rejected', function () {
    publicApiTenant('suspended-agency', ['status' => 'suspended']);

    $this->getJson('/api/v1/packages')->assertNotFound()->assertJson(['message' => 'Tenant not found.']);
    $this->getJson('/api/v1/packages?tenant=missing')->assertNotFound();
    $this->getJson('/api/v1/packages?tenant=suspended-agency')->assertNotFound();
});

test('the tenant can be resolved from a subdomain of the public domain', function () {
    config(['app.public_domain' => 'trips.test']);
    $tenant = publicApiTenant('himalaya');
    publicApiPackage($tenant, 'everest-base-camp');

    $this->getJson('http://himalaya.trips.test/api/v1/packages')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'everest-base-camp');

    $this->getJson('http://unknown.trips.test/api/v1/packages')->assertNotFound();
});

test('the package list only shows the tenant\'s published public packages without internal pricing', function () {
    $tenant = publicApiTenant('himalaya');
    publicApiPackage($tenant, 'everest-base-camp');
    publicApiPackage($tenant, 'draft-trip', ['status' => 'draft']);
    publicApiPackage($tenant, 'private-trip', ['is_public' => false]);
    publicApiPackage(publicApiTenant('other-agency'), 'other-tenant-trip');

    $response = $this->getJson('/api/v1/packages?tenant=himalaya')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    $response
        ->assertJsonPath('data.0.slug', 'everest-base-camp')
        ->assertJsonPath('data.0.price', ['amount' => 80000, 'currency' => 'NPR'])
        ->assertJsonMissingPath('data.0.base_price')
        ->assertJsonPath('meta.total', 1);
});

test('package detail includes the itinerary and only upcoming bookable departures', function () {
    $tenant = publicApiTenant('himalaya');
    $package = publicApiPackage($tenant, 'everest-base-camp', [
        'itinerary' => [['title' => 'Arrive in Kathmandu', 'description' => 'Hotel transfer']],
    ]);
    $upcoming = publicApiDeparture($package, ['price_override' => 75000, 'overbooking_buffer' => 2]);
    publicApiDeparture($package, ['status' => 'cancelled']);
    publicApiDeparture($package, ['start_date' => now()->subDays(3)->toDateString(), 'end_date' => now()->toDateString()]);

    $this->getJson('/api/v1/packages/everest-base-camp?tenant=himalaya')
        ->assertOk()
        ->assertJsonPath('data.itinerary.0', ['day' => 1, 'title' => 'Arrive in Kathmandu', 'description' => 'Hotel transfer'])
        ->assertJsonCount(1, 'data.departures')
        ->assertJsonPath('data.departures.0.id', $upcoming->id)
        ->assertJsonPath('data.departures.0.price.amount', 75000)
        ->assertJsonPath('data.departures.0.seats_remaining', 10)
        ->assertJsonMissingPath('data.departures.0.booked_slots');
});

test('package detail is not found for another tenant\'s or an unpublished package', function () {
    publicApiTenant('himalaya');
    publicApiPackage(publicApiTenant('other-agency'), 'other-tenant-trip');
    publicApiPackage(Tenant::query()->where('slug', 'himalaya')->first(), 'draft-trip', ['status' => 'draft']);

    $this->getJson('/api/v1/packages/other-tenant-trip?tenant=himalaya')->assertNotFound();
    $this->getJson('/api/v1/packages/draft-trip?tenant=himalaya')->assertNotFound();
});

test('departures can be filtered by package and report full departures as waitlist-only', function () {
    $tenant = publicApiTenant('himalaya');
    $everest = publicApiPackage($tenant, 'everest-base-camp');
    $annapurna = publicApiPackage($tenant, 'annapurna-circuit');
    $full = publicApiDeparture($everest, ['booked_slots' => 10, 'status' => 'full']);
    publicApiDeparture($annapurna);

    $this->getJson('/api/v1/departures?tenant=himalaya&package=everest-base-camp')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $full->id)
        ->assertJsonPath('data.0.is_full', true)
        ->assertJsonPath('data.0.waitlist_available', true)
        ->assertJsonPath('data.0.seats_remaining', 0);
});

test('cached catalog responses are refreshed when a package changes', function () {
    $tenant = publicApiTenant('himalaya');
    $package = publicApiPackage($tenant, 'everest-base-camp');

    $this->getJson('/api/v1/packages?tenant=himalaya')->assertJsonPath('data.0.name', 'Everest Base Camp');

    app(TenantContext::class)->wrap($tenant, fn () => $package->update(['name' => 'Everest Base Camp Trek']));

    $this->getJson('/api/v1/packages?tenant=himalaya')->assertJsonPath('data.0.name', 'Everest Base Camp Trek');
});

test('an inquiry creates a public website lead and a new customer', function () {
    $tenant = publicApiTenant('himalaya');
    publicApiPackage($tenant, 'everest-base-camp');

    $this->postJson('/api/v1/inquiries?tenant=himalaya', [
        ...publicApiContact(),
        'package_slug' => 'everest-base-camp',
        'pax_count' => 3,
        'message' => 'Is October a good time?',
    ])->assertCreated();

    $lead = Lead::withoutGlobalScopes()->sole();
    $customer = Customer::withoutGlobalScopes()->sole();

    expect($lead->tenant_id)->toBe($tenant->id)
        ->and($lead->origin)->toBe('public_website')
        ->and($lead->status)->toBe('new')
        ->and($lead->pax_count)->toBe(3)
        ->and($lead->destination)->toBe('Everest Base Camp')
        ->and($lead->customer_id)->toBe($customer->id)
        ->and($lead->notes)->toContain('Is October a good time?')
        ->and($customer->tenant_id)->toBe($tenant->id)
        ->and($customer->email)->toBe('asha@example.com');
});

test('an inquiry from a known email reuses the customer without overwriting their details', function () {
    $tenant = publicApiTenant('himalaya');
    $customer = app(TenantContext::class)->wrap($tenant, fn (): Customer => Customer::factory()->create([
        'name' => 'Asha Gurung',
        'email' => 'asha@example.com',
        'phone' => '+9771111111',
    ]));

    $this->postJson('/api/v1/inquiries?tenant=himalaya', [
        'name' => 'Someone Else',
        'email' => 'ASHA@example.com',
        'phone' => '+9772222222',
    ])->assertCreated();

    expect(Customer::withoutGlobalScopes()->count())->toBe(1)
        ->and(Lead::withoutGlobalScopes()->sole()->customer_id)->toBe($customer->id)
        ->and($customer->fresh()->only(['name', 'phone']))->toBe(['name' => 'Asha Gurung', 'phone' => '+9771111111']);
});

test('an inquiry requires contact details and a public package', function () {
    publicApiTenant('himalaya');

    $this->postJson('/api/v1/inquiries?tenant=himalaya', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'name' => 'The name field is required.',
            'email' => 'The email field is required.',
        ]);

    $this->postJson('/api/v1/inquiries?tenant=himalaya', [...publicApiContact(), 'package_slug' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['package_slug' => 'The selected package is not available.']);

    expect(Lead::withoutGlobalScopes()->count())->toBe(0);
});

test('visitors can join the waitlist of a full departure in order, once each', function () {
    $tenant = publicApiTenant('himalaya');
    $departure = publicApiDeparture(publicApiPackage($tenant, 'everest-base-camp'), ['booked_slots' => 10, 'status' => 'full']);
    $url = "/api/v1/departures/{$departure->id}/waitlist?tenant=himalaya";

    $this->postJson($url, [...publicApiContact(), 'pax_count' => 2])
        ->assertCreated()
        ->assertJsonPath('data.position', 1);
    $this->postJson($url, ['name' => 'Bikash Rai', 'email' => 'bikash@example.com'])
        ->assertCreated()
        ->assertJsonPath('data.position', 2);
    $this->postJson($url, publicApiContact())
        ->assertOk()
        ->assertJsonPath('data.position', 1);

    $entry = BookingWaitlist::withoutGlobalScopes()->where('position', 1)->sole();

    expect(BookingWaitlist::withoutGlobalScopes()->count())->toBe(2)
        ->and($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->status)->toBe('waiting')
        ->and($entry->pax_requested)->toBe(2)
        ->and(Lead::withoutGlobalScopes()->find($entry->lead_id)->origin)->toBe('public_website');
});

test('the waitlist is refused while a departure still has enough seats', function () {
    $tenant = publicApiTenant('himalaya');
    $departure = publicApiDeparture(publicApiPackage($tenant, 'everest-base-camp'), ['booked_slots' => 8]);
    $url = "/api/v1/departures/{$departure->id}/waitlist?tenant=himalaya";

    $this->postJson($url, [...publicApiContact(), 'pax_count' => 2])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('departure');

    $this->postJson($url, [...publicApiContact(), 'pax_count' => 3])->assertCreated();
});

test('the waitlist is not available for another tenant\'s departure', function () {
    publicApiTenant('himalaya');
    $foreign = publicApiDeparture(publicApiPackage(publicApiTenant('other-agency'), 'other-trip'), ['booked_slots' => 10, 'status' => 'full']);

    $this->postJson("/api/v1/departures/{$foreign->id}/waitlist?tenant=himalaya", publicApiContact())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['departure' => 'This departure is not available.']);

    expect(BookingWaitlist::withoutGlobalScopes()->count())->toBe(0);
});

test('public submissions are rate limited per IP', function () {
    publicApiTenant('himalaya');

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/inquiries?tenant=himalaya', publicApiContact())->assertCreated();
    }

    $this->postJson('/api/v1/inquiries?tenant=himalaya', publicApiContact())->assertTooManyRequests();
});
