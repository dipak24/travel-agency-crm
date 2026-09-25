<?php

use App\Models\EmailUnsubscribe;
use App\Models\Tenant;
use App\Services\Mail\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the unsubscribe page requires a valid signature', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);

    $this->get("/unsubscribe?scope={$tenant->id}&email=asha@example.com")->assertForbidden();
    $this->post("/unsubscribe?scope={$tenant->id}&email=asha@example.com")->assertForbidden();

    expect(EmailUnsubscribe::query()->count())->toBe(0);
});

test('opening the link only asks for confirmation, and confirming unsubscribes from that tenant', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $url = app(CampaignSender::class)->unsubscribeUrl($tenant->id, 'Asha@Example.com');

    $this->get($url)->assertOk()->assertSee('Unsubscribe from marketing emails?')->assertSee('Northwind Travel');
    expect(EmailUnsubscribe::query()->count())->toBe(0);

    $this->post($url)->assertOk()->assertSee('You\'re unsubscribed', escape: false);
    $this->post($url)->assertOk();

    expect(EmailUnsubscribe::query()->sole()->only(['tenant_id', 'email']))
        ->toBe(['tenant_id' => $tenant->id, 'email' => 'asha@example.com']);
});

test('a one-click unsubscribe from a mail provider is accepted without a CSRF token', function () {
    $url = app(CampaignSender::class)->unsubscribeUrl(null, 'lead@example.com');

    $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertNoContent(200);

    expect(EmailUnsubscribe::query()->sole()->only(['tenant_id', 'email']))
        ->toBe(['tenant_id' => null, 'email' => 'lead@example.com']);
});
