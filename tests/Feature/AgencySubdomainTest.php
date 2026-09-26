<?php

use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Models\Customer;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\CustomerAccountLink;
use App\Notifications\PasswordResetRequested;
use App\Notifications\StaffAccountLink;
use App\Services\Auth\AccountSetupLinks;
use App\Support\AgencySubdomain;
use App\Support\TenantContext;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Two agencies that both have a customer with the same email address.
 *
 * @return array{0: Tenant, 1: Customer, 2: Tenant, 3: Customer}
 */
function twoAgenciesSharingAnEmail(): array
{
    $north = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $blue = Tenant::query()->create(['name' => 'Blue Peak', 'slug' => 'blue-peak']);

    $northCustomer = app(TenantContext::class)->wrap($north, fn (): Customer => Customer::factory()->create(['email' => 'shared@example.test', 'password' => 'north-password']));
    $blueCustomer = app(TenantContext::class)->wrap($blue, fn (): Customer => Customer::factory()->create(['email' => 'shared@example.test', 'password' => 'blue-password']));

    return [$north, $northCustomer, $blue, $blueCustomer];
}

test('the portal only answers on an existing agency subdomain', function () {
    $agency = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $domain = config('agency.domain');

    $this->get(portalUrl($agency, '/portal/login'))->assertOk();
    // Absolute: after a request to an agency host, the test client roots relative URLs there.
    $this->get(config('app.url').'/portal/login')->assertNotFound();
    $this->get("http://unknown-agency.{$domain}/portal/login")->assertNotFound();
    $this->get("http://admin.{$domain}/portal/login")->assertNotFound();
});

test('staff sign in on their agency subdomain, and the super admin only on the platform domain', function () {
    $agency = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);

    $this->get(portalUrl($agency, '/tenant/login'))->assertOk();
    $this->get(portalUrl($agency, '/admin/login'))->assertNotFound();
    $this->get(config('app.url').'/admin/login')->assertOk();
    $this->get(config('app.url').'/tenant/login')
        ->assertNotFound()
        ->assertSeeText('Please use your agency\'s link');
});

test('a staff member of one agency is refused on another agency\'s staff panel', function () {
    $north = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $blue = Tenant::query()->create(['name' => 'Blue Peak', 'slug' => 'blue-peak']);
    $blueStaff = app(TenantContext::class)->wrap($blue, fn (): TenantUser => TenantUser::factory()->create(['password' => 'blue-password']));
    app(TenantContext::class)->clear();

    $this->actingAs($blueStaff, 'tenant')->get(portalUrl($north, '/tenant'))->assertForbidden();

    app(AgencySubdomain::class)->set($north);

    expect(auth('tenant')->validate(['email' => $blueStaff->email, 'password' => 'blue-password']))->toBeFalse();

    app(AgencySubdomain::class)->set($blue);

    expect(auth('tenant')->validate(['email' => $blueStaff->email, 'password' => 'blue-password']))->toBeTrue();
});

test('the staff and portal login pages carry the agency\'s own name, logo and favicon', function () {
    $agency = Tenant::query()->create([
        'name' => 'Northwind Travel',
        'slug' => 'northwind',
        'logo' => 'tenant-logos/northwind.png',
        'favicon' => 'tenant-favicons/northwind.png',
        'primary_color' => '#0f766e',
    ]);

    foreach (['/tenant/login', '/portal/login'] as $path) {
        $this->get(portalUrl($agency, $path))
            ->assertOk()
            ->assertSee('Northwind Travel')
            ->assertSee(Storage::disk('public')->url('tenant-logos/northwind.png'), escape: false)
            ->assertSee(Storage::disk('public')->url('tenant-favicons/northwind.png'), escape: false)
            ->assertSee('--primary-500:', escape: false);
    }
});

test('staff password links point at the agency subdomain', function () {
    $agency = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $staff = app(TenantContext::class)->wrap($agency, fn (): TenantUser => TenantUser::factory()->create(['password' => null]));
    Notification::fake();

    app(AccountSetupLinks::class)->sendStaffLink($staff);

    $url = Notification::sent($staff, StaffAccountLink::class)->first()->url;

    expect($url)->toStartWith(AgencySubdomain::rootFor($agency).'/tenant/password-reset/');
    $this->get($url)->assertOk();
});

test('logging in on an agency portal only matches that agency\'s customer', function () {
    [$north, $northCustomer, , $blueCustomer] = twoAgenciesSharingAnEmail();

    app(AgencySubdomain::class)->set($north);

    expect(auth('customer')->attempt(['email' => 'shared@example.test', 'password' => 'blue-password']))->toBeFalse()
        ->and(auth('customer')->attempt(['email' => 'shared@example.test', 'password' => 'north-password']))->toBeTrue()
        ->and(auth('customer')->id())->toBe($northCustomer->id)
        ->and(auth('customer')->id())->not->toBe($blueCustomer->id);
});

test('a customer of one agency is refused on another agency\'s portal', function () {
    [$north, , , $blueCustomer] = twoAgenciesSharingAnEmail();

    $this->actingAs($blueCustomer, 'customer')->get(portalUrl($north))->assertForbidden();
    $this->actingAs($blueCustomer, 'customer')->get(portalUrl($blueCustomer->tenant))->assertOk();
});

test('forgot password on an agency portal emails only that agency\'s customer, with a link on its subdomain', function () {
    [$north, $northCustomer, , $blueCustomer] = twoAgenciesSharingAnEmail();
    Notification::fake();
    app(AgencySubdomain::class)->set($north);
    Filament::setCurrentPanel('portal');

    Livewire::test(RequestPasswordReset::class)
        ->set('data.email', 'shared@example.test')
        ->call('request');

    Notification::assertSentTo($northCustomer, PasswordResetRequested::class);
    Notification::assertNotSentTo($blueCustomer, PasswordResetRequested::class);

    $mail = Notification::sent($northCustomer, PasswordResetRequested::class)->first()->toMail($northCustomer);

    expect($mail->viewData['html'])->toContain(AgencySubdomain::rootFor($north).'/portal/password-reset/');
});

test('staff-sent setup links point at the customer\'s own agency subdomain', function () {
    [$north, $northCustomer] = twoAgenciesSharingAnEmail();
    Notification::fake();
    app(TenantContext::class)->set($north);

    app(AccountSetupLinks::class)->sendCustomerLink($northCustomer);

    $url = Notification::sent($northCustomer, CustomerAccountLink::class)->first()->url;

    expect($url)->toStartWith(AgencySubdomain::rootFor($north).'/portal/');
    $this->get($url)->assertOk();
});

test('an agency slug must be a valid, non-reserved subdomain', function (string $slug) {
    $this->seed();
    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $plan = SubscriptionPlan::query()->create(['name' => 'Growth']);

    $test = Livewire::actingAs($admin, 'super_admin')->test(CreateTenant::class);

    foreach ([
        'name' => 'Some Agency', 'slug' => $slug, 'status' => 'trial', 'timezone' => 'UTC', 'currency' => 'USD',
        'owner_name' => 'Owner', 'owner_email' => 'owner@some-agency.test', 'plan_id' => $plan->getKey(),
    ] as $key => $value) {
        $test->set("data.{$key}", $value);
    }

    $test->call('create')->assertHasFormErrors(['slug']);

    expect(Tenant::query()->where('slug', $slug)->exists())->toBeFalse();
})->with(['reserved name' => 'admin', 'uppercase' => 'Northwind', 'underscore' => 'north_wind', 'leading hyphen' => '-northwind']);
