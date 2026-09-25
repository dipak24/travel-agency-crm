<?php

use App\Filament\Resources\PlatformCampaignResource\Pages\ListPlatformCampaigns;
use App\Filament\Resources\SaasLeadResource\Pages\CreateSaasLead;
use App\Models\EmailCampaign;
use App\Models\SaasLead;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function marketingAdmin(): SuperAdmin
{
    return SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
}

test('a super admin can open every marketing page', function () {
    $this->seed();

    foreach (['email-templates', 'campaigns', 'saas-leads'] as $resource) {
        $this->actingAs(marketingAdmin(), 'super_admin')->get("/admin/{$resource}")->assertOk()->assertSee("/admin/{$resource}/create", false);
        $this->actingAs(marketingAdmin(), 'super_admin')->get("/admin/{$resource}/create")->assertOk();
    }
});

test('the platform campaign list never shows tenant campaigns', function () {
    $this->seed();
    $platform = EmailCampaign::query()->create(['owner_type' => 'platform', 'name' => 'Launch', 'audience_type' => 'tenants']);
    $tenantCampaign = EmailCampaign::query()->create([
        'owner_type' => 'tenant', 'tenant_id' => Tenant::query()->value('id'), 'name' => 'Spring', 'audience_type' => 'customers',
    ]);
    Filament::setCurrentPanel('admin');

    Livewire::actingAs(marketingAdmin(), 'super_admin')->test(ListPlatformCampaigns::class)
        ->assertCanSeeTableRecords([$platform])
        ->assertCanNotSeeTableRecords([$tenantCampaign]);
});

test('a super admin can record a SaaS lead, with unique emails', function () {
    $this->seed();
    SaasLead::query()->create(['name' => 'Existing', 'email' => 'taken@agency.test']);
    Filament::setCurrentPanel('admin');

    Livewire::actingAs(marketingAdmin(), 'super_admin')->test(CreateSaasLead::class)
        ->set('data.name', 'Himalaya Treks')
        ->set('data.email', 'hello@himalaya.test')
        ->set('data.company', 'Himalaya Treks Pvt Ltd')
        ->set('data.status', 'new')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SaasLead::query()->where('email', 'hello@himalaya.test')->value('company'))->toBe('Himalaya Treks Pvt Ltd');

    Livewire::actingAs(marketingAdmin(), 'super_admin')->test(CreateSaasLead::class)
        ->set('data.name', 'Duplicate')
        ->set('data.email', 'taken@agency.test')
        ->set('data.status', 'new')
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);
});
