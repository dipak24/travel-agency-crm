<?php

use App\Filament\Tenant\Pages\WebsiteSettings;
use App\Models\PublicLeadPage;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\Dns\TxtRecordLookup;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function websiteOwner(): TenantUser
{
    return TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();
}

/**
 * @param  list<string>  $records
 */
function fakeTxtRecords(array $records): void
{
    app()->instance(TxtRecordLookup::class, new class($records) extends TxtRecordLookup
    {
        /**
         * @param  list<string>  $records
         */
        public function __construct(private array $records) {}

        public function lookup(string $host): array
        {
            return $this->records;
        }
    });
}

function websitePageFor(Tenant $tenant, array $attributes): PublicLeadPage
{
    app(TenantContext::class)->set($tenant);
    $page = PublicLeadPage::query()->create($attributes);
    app(TenantContext::class)->clear();

    return $page;
}

test('a tenant owner can publish the website with a theme and contact details', function () {
    $this->seed();
    $owner = websiteOwner();
    $this->actingAsStaff($owner);

    Livewire::actingAs($owner, 'tenant')->test(WebsiteSettings::class)
        ->set('data.is_active', true)
        ->set('data.theme.template', 'modern')
        ->set('data.theme.accent_color', '#0f766e')
        ->set('data.theme.hero_title', 'Trek the Himalaya')
        ->set('data.contact_settings.email', 'hello@demo-travel.test')
        ->set('data.contact_settings.phone', '+977 1 5550100')
        ->call('save')
        ->assertHasNoFormErrors();

    $page = PublicLeadPage::query()->withoutGlobalScopes()->sole();

    expect($page->tenant_id)->toBe($owner->tenant_id)
        ->and($page->is_active)->toBeTrue()
        ->and($page->theme['template'])->toBe('modern')
        ->and($page->contact_settings['email'])->toBe('hello@demo-travel.test')
        ->and($page->custom_domain)->toBeNull();
});

test('a custom domain must be a bare domain outside the platform domain', function (string $domain, string $message) {
    $this->seed();
    $owner = websiteOwner();
    $this->actingAsStaff($owner);

    Livewire::actingAs($owner, 'tenant')->test(WebsiteSettings::class)
        ->set('data.custom_domain', $domain)
        ->call('save')
        ->assertHasFormErrors(['custom_domain' => $message]);
})->with([
    'with scheme' => ['https://trips.example.org/', 'Enter just the domain, like trips.youragency.com — without https or a path.'],
    'platform subdomain' => [fn (): string => 'other.'.config('agency.domain'), 'Use your own domain here — your agency address already works without one.'],
]);

test('a domain already connected to another agency is rejected', function () {
    $this->seed();
    $other = Tenant::query()->create(['name' => 'Other Agency', 'slug' => 'other-agency']);
    websitePageFor($other, ['custom_domain' => 'trips.example.org']);

    $owner = websiteOwner();
    $this->actingAsStaff($owner);

    Livewire::actingAs($owner, 'tenant')->test(WebsiteSettings::class)
        ->set('data.custom_domain', 'trips.example.org')
        ->call('save')
        ->assertHasFormErrors(['custom_domain' => 'This domain is already connected to another agency.']);
});

test('a custom domain is verified only when its TXT record carries the token', function () {
    $this->seed();
    $owner = websiteOwner();
    $page = websitePageFor($owner->tenant, ['custom_domain' => 'Trips.Example.org', 'is_active' => true]);

    expect($page->custom_domain)->toBe('trips.example.org')
        ->and($page->verificationRecordName())->toBe('_travelcrm-verification.trips.example.org');

    $this->actingAsStaff($owner);

    fakeTxtRecords(['some-other-record']);
    Livewire::actingAs($owner, 'tenant')->test(WebsiteSettings::class)->call('verifyDomain');
    expect($page->fresh()->hasVerifiedDomain())->toBeFalse();

    fakeTxtRecords([$page->verificationRecordValue()]);
    Livewire::actingAs($owner, 'tenant')->test(WebsiteSettings::class)->call('verifyDomain');
    expect($page->fresh()->hasVerifiedDomain())->toBeTrue();
});

test('changing the custom domain requires verifying it again', function () {
    $this->seed();
    $page = websitePageFor(websiteOwner()->tenant, ['custom_domain' => 'trips.example.org']);
    $page->forceFill(['domain_verified_at' => now()])->save();
    $oldToken = $page->domain_verification_token;

    $page->update(['custom_domain' => 'travel.example.org']);

    expect($page->hasVerifiedDomain())->toBeFalse()
        ->and($page->domain_verification_token)->not->toBe($oldToken);
});

test('staff without the manage settings permission cannot open the website page', function () {
    $this->seed();
    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    app(TenantContext::class)->set($tenant);
    $agent = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAsStaff($agent)->get('/tenant/website')->assertForbidden();
});

test('the public API serves a published site and 404s an unpublished one', function () {
    $this->seed();
    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    $page = websitePageFor($tenant, [
        'is_active' => false,
        'theme' => ['template' => 'minimal', 'hero_title' => 'Trek the Himalaya'],
        'contact_settings' => ['email' => 'hello@demo-travel.test'],
    ]);

    $this->getJson('/api/v1/site?tenant=demo-travel')->assertNotFound();

    $page->update(['is_active' => true]);

    $this->getJson('/api/v1/site?tenant=demo-travel')
        ->assertOk()
        ->assertJsonPath('data.name', 'Demo Travel Agency')
        ->assertJsonPath('data.theme.template', 'minimal')
        ->assertJsonPath('data.theme.hero_title', 'Trek the Himalaya')
        ->assertJsonPath('data.contact.email', 'hello@demo-travel.test')
        ->assertJsonPath('data.custom_domain', null);
});

test('the public API resolves an agency by its custom domain only once verified', function () {
    $this->seed();
    $page = websitePageFor(Tenant::query()->where('slug', 'demo-travel')->firstOrFail(), [
        'custom_domain' => 'trips.example.org',
        'is_active' => true,
    ]);

    $this->getJson('http://trips.example.org/api/v1/site')->assertNotFound()->assertJson(['message' => 'Tenant not found.']);

    $page->forceFill(['domain_verified_at' => now()])->save();

    $this->getJson('http://trips.example.org/api/v1/site')
        ->assertOk()
        ->assertJsonPath('data.custom_domain', 'trips.example.org')
        ->assertJsonPath('data.url', 'https://trips.example.org');
});

test('the settings show the departures widget code, and the marketing website must be an http(s) link', function () {
    $this->seed();
    $owner = websiteOwner();
    $this->actingAsStaff($owner);

    Livewire::actingAs($owner, 'tenant')->test(WebsiteSettings::class)
        ->assertSee('data-crm-departures')
        ->assertSee(portalUrl($owner->tenant, '/js/departures-widget.js'))
        ->set('data.contact_settings.website_url', 'javascript:alert(1)')
        ->call('save')
        ->assertHasFormErrors(['contact_settings.website_url'])
        ->set('data.contact_settings.website_url', 'https://www.demo-travel.test')
        ->set('data.contact_settings.terms_url', 'javascript:alert(1)')
        ->call('save')
        ->assertHasFormErrors(['contact_settings.terms_url'])
        ->set('data.contact_settings.terms_url', 'https://www.demo-travel.test/terms')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(PublicLeadPage::query()->withoutGlobalScopes()->sole()->contact_settings)
        ->toMatchArray(['website_url' => 'https://www.demo-travel.test', 'terms_url' => 'https://www.demo-travel.test/terms']);
});
