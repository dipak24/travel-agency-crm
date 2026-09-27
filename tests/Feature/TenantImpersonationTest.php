<?php

use App\Filament\Resources\TenantResource\Pages\ListTenants;
use App\Models\Activity;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\Auth\TenantImpersonation;
use App\Support\AgencySubdomain;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function impersonatingAdmin(): SuperAdmin
{
    return SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
}

function impersonatedStaff(): TenantUser
{
    return TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();
}

test('a super admin link signs in as the staff member on the agency subdomain and is audited', function () {
    $this->seed();
    $staff = impersonatedStaff();

    $url = app(TenantImpersonation::class)->start(impersonatingAdmin(), $staff);

    expect($url)->toStartWith(AgencySubdomain::rootFor($staff->tenant).'/impersonate/');

    $this->get($url)->assertRedirect('/tenant');

    $this->assertAuthenticatedAs($staff, 'tenant');
    $this->get(AgencySubdomain::url($staff->tenant, '/tenant'))
        ->assertOk()
        ->assertSee('on behalf of platform admin')
        ->assertSee('End impersonation');

    $entry = Activity::query()->where('event', 'impersonation_started')->sole();
    expect($entry->causer_type)->toBe(SuperAdmin::class)
        ->and($entry->subject_id)->toBe($staff->id)
        ->and($entry->tenant_id)->toBe($staff->tenant_id);
});

test('an impersonation link works only once', function () {
    $this->seed();
    $url = app(TenantImpersonation::class)->start(impersonatingAdmin(), impersonatedStaff());

    $this->get($url)->assertRedirect('/tenant');
    auth('tenant')->logout();

    $this->get($url)->assertForbidden();
});

test('an impersonation link expires after a minute', function () {
    $this->seed();
    $url = app(TenantImpersonation::class)->start(impersonatingAdmin(), impersonatedStaff());

    $this->travel(2)->minutes();

    $this->get($url)->assertForbidden();
    $this->assertGuest('tenant');
});

test('an impersonation token cannot be redeemed on another agency', function () {
    $this->seed();
    $other = Tenant::query()->create(['name' => 'Other Agency', 'slug' => 'other-agency']);
    $url = app(TenantImpersonation::class)->start(impersonatingAdmin(), impersonatedStaff());
    $token = basename(parse_url($url, PHP_URL_PATH));

    $otherAgencyUrl = AgencySubdomain::within($other, fn (): string => URL::temporarySignedRoute('impersonation.enter', now()->addMinute(), ['token' => $token]));

    $this->get($otherAgencyUrl)->assertForbidden();
    $this->assertGuest('tenant');
});

test('ending impersonation signs out, records the end and returns to the admin panel', function () {
    $this->seed();
    $staff = impersonatedStaff();
    $this->get(app(TenantImpersonation::class)->start(impersonatingAdmin(), $staff));

    $this->post(AgencySubdomain::url($staff->tenant, '/impersonate/leave'))
        ->assertRedirect(rtrim(config('app.url'), '/').'/admin/tenants');

    $this->assertGuest('tenant');
    $entry = Activity::query()->where('event', 'impersonation_ended')->sole();
    expect($entry->causer_type)->toBe(SuperAdmin::class)
        ->and($entry->subject_id)->toBe($staff->id);
});

test('staff of a suspended agency cannot be impersonated', function () {
    $this->seed();
    $staff = impersonatedStaff();
    $staff->tenant->update(['status' => 'suspended']);

    app(TenantImpersonation::class)->start(impersonatingAdmin(), $staff->fresh());
})->throws(InvalidArgumentException::class);

test('the impersonate action is offered for active agencies only', function () {
    $this->seed();
    $active = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    $suspended = Tenant::query()->create(['name' => 'Suspended Agency', 'slug' => 'suspended-agency', 'status' => 'suspended']);
    Filament::setCurrentPanel('admin');

    Livewire::actingAs(impersonatingAdmin(), 'super_admin')->test(ListTenants::class)
        ->assertActionVisible(TestAction::make('impersonate')->table($active))
        ->assertActionHidden(TestAction::make('impersonate')->table($suspended));
});
