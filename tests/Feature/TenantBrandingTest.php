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

    $this->actingAs($staff, 'tenant')->get('/tenant')
        ->assertOk()
        ->assertSee("--primary-500:{$expectedPrimary500};", false)
        ->assertSee("--secondary-500:{$expectedSecondary500};", false);
});

test('the tenant panel does not override colors when no brand color is set', function () {
    $this->seed();

    $staff = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $this->actingAs($staff, 'tenant')->get('/tenant')
        ->assertOk()
        ->assertDontSee(':root{--primary', false);
});
