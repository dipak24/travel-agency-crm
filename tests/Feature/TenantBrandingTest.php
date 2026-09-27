<?php

use App\Models\Tenant;
use App\Models\TenantUser;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the tenant panel reflects the logged-in tenant\'s primary and secondary brand colors', function () {
    $this->seed();

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    $tenant->update(['primary_color' => '#ff0000', 'secondary_color' => '#00ff00']);

    $staff = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $expectedPrimary500 = Color::generatePalette('#ff0000')[500];
    $expectedSecondary500 = Color::generatePalette('#00ff00')[500];

    $this->actingAsStaff($staff)->get('/tenant')
        ->assertOk()
        ->assertSee("--primary-500:{$expectedPrimary500};", false)
        ->assertSee("--secondary-500:{$expectedSecondary500};", false);
});

test('the tenant panel does not override colors when no brand color is set', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $this->actingAsStaff($staff)->get('/tenant')
        ->assertOk()
        ->assertDontSee(':root{--primary', false);
});

test('the agency login page shows the business name when no logo is uploaded', function () {
    $this->seed();
    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();

    $this->get(portalUrl($tenant, '/tenant/login'))
        ->assertOk()
        ->assertSeeInOrder(['fi-logo', 'Demo Travel Agency'], false)
        ->assertDontSee('<img alt="Demo Travel Agency logo"', false);
});

test('an uploaded logo is shown at its natural size, not cropped or boxed', function () {
    $this->seed();
    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    $tenant->update(['logo' => 'tenant-logos/wide-logo.png']);

    $this->get(portalUrl($tenant, '/tenant/login'))
        ->assertOk()
        ->assertSee('tenant-logos/wide-logo.png', false)
        ->assertSee('style="height: auto;"', false)
        ->assertSee('img.fi-logo{width:auto;height:auto;max-width:100%}', false)
        ->assertDontSee('max-height:6rem', false);
});
