<?php

use App\Filament\Tenant\Resources\EmailCampaignResource\Pages\ListEmailCampaigns;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\EmailUnsubscribe;
use App\Models\PlatformEmailTemplate;
use App\Models\SaasLead;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\CampaignEmail;
use App\Services\Mail\CampaignSender;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\SentMessage;

uses(RefreshDatabase::class);

function campaignTenant(string $slug): Tenant
{
    return Tenant::query()->create(['name' => ucfirst($slug).' Travel', 'slug' => $slug]);
}

function campaignOwner(Tenant $tenant): TenantUser
{
    return app(TenantContext::class)->wrap($tenant, function () use ($tenant): TenantUser {
        $owner = TenantUser::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $owner->assignRole(Role::firstOrCreate(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]));

        return $owner;
    });
}

function campaignCustomer(Tenant $tenant, string $email, string $type = 'individual'): Customer
{
    return app(TenantContext::class)->wrap($tenant, fn (): Customer => Customer::factory()->create([
        'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'type' => $type,
    ]));
}

function campaignForTenant(Tenant $tenant, array $attributes = [], string $templateStatus = 'active'): EmailCampaign
{
    $template = app(TenantContext::class)->wrap($tenant, fn (): EmailTemplate => EmailTemplate::query()->create([
        'category' => EmailTemplate::CATEGORY_MARKETING,
        'name' => 'Spring offers',
        'subject' => 'Spring offers from {{ tenant_name }}',
        'body_html' => '<p>Hi {{ recipient_name }}, 10% off treks this spring.</p>',
        'status' => $templateStatus,
    ]));

    return EmailCampaign::query()->create([
        'owner_type' => EmailCampaign::OWNER_TENANT,
        'tenant_id' => $tenant->id,
        'template_id' => $template->id,
        'name' => 'Spring campaign',
        'audience_type' => 'customers',
        'status' => 'draft',
        ...$attributes,
    ]);
}

function campaignForPlatform(string $audience, array $filter = []): EmailCampaign
{
    $template = PlatformEmailTemplate::query()->create([
        'category' => 'announcement', 'name' => 'Launch', 'subject' => 'New features',
        'body_html' => '<p>Hello {{ recipient_name }}</p>', 'status' => 'active',
    ]);

    return EmailCampaign::query()->create([
        'owner_type' => EmailCampaign::OWNER_PLATFORM,
        'template_id' => $template->id,
        'name' => 'Launch announcement',
        'audience_type' => $audience,
        'audience_filter' => $filter,
        'status' => 'draft',
    ]);
}

/**
 * @return list<string>
 */
function campaignSentTo(): array
{
    return Mail::getSymfonyTransport()->messages()
        ->map(fn (SentMessage $message): string => $message->getOriginalMessage()->getTo()[0]->getAddress())
        ->sort()->values()->all();
}

test('a tenant campaign reaches its own customers, skipping unsubscribes, with an unsubscribe link and header', function () {
    $tenant = campaignTenant('northwind');
    campaignCustomer($tenant, 'asha@example.com');
    campaignCustomer($tenant, 'bikash@example.com');
    campaignCustomer($tenant, 'optout@example.com');
    campaignCustomer(campaignTenant('other'), 'other-tenant@example.com');
    EmailUnsubscribe::query()->create(['tenant_id' => $tenant->id, 'email' => 'optout@example.com', 'unsubscribed_at' => now()]);
    $campaign = campaignForTenant($tenant);

    app(CampaignSender::class)->sendNow($campaign);

    expect(campaignSentTo())->toBe(['asha@example.com', 'bikash@example.com'])
        ->and($campaign->fresh()->only(['status', 'total_recipients', 'sent_count', 'failed_count']))
        ->toBe(['status' => 'sent', 'total_recipients' => 2, 'sent_count' => 2, 'failed_count' => 0]);

    $email = Mail::getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($email->getSubject())->toBe('Spring offers from Northwind Travel')
        ->and($email->getHtmlBody())->toContain('10% off treks')->toContain('/unsubscribe?')
        ->and($email->getHeaders()->get('List-Unsubscribe')->getBodyAsString())->toContain('/unsubscribe?')->toContain('scope='.$tenant->id)
        ->and($email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click');
});

test('a customer campaign can be narrowed to customer types', function () {
    $tenant = campaignTenant('northwind');
    campaignCustomer($tenant, 'asha@example.com');
    campaignCustomer($tenant, 'agency@example.com', 'agency');

    app(CampaignSender::class)->sendNow(campaignForTenant($tenant, ['audience_filter' => ['customer_types' => ['agency']]]));

    expect(campaignSentTo())->toBe(['agency@example.com']);
});

test('a staff campaign only reaches active staff of the tenant', function () {
    $tenant = campaignTenant('northwind');
    app(TenantContext::class)->wrap($tenant, function () use ($tenant): void {
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'email' => 'active@northwind.test', 'status' => 'active']);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'email' => 'inactive@northwind.test', 'status' => 'inactive']);
    });

    app(CampaignSender::class)->sendNow(campaignForTenant($tenant, ['audience_type' => 'staff']));

    expect(campaignSentTo())->toBe(['active@northwind.test']);
});

test('a large audience is sent across several batches without repeats', function () {
    Notification::fake();
    $tenant = campaignTenant('northwind');
    app(TenantContext::class)->wrap($tenant, fn () => Customer::factory()->count(CampaignSender::BATCH_SIZE + 5)->create());
    $campaign = campaignForTenant($tenant);

    app(CampaignSender::class)->sendNow($campaign);

    Notification::assertSentOnDemandTimes(CampaignEmail::class, CampaignSender::BATCH_SIZE + 5);
    expect($campaign->fresh()->only(['status', 'sent_count']))->toBe(['status' => 'sent', 'sent_count' => CampaignSender::BATCH_SIZE + 5]);
});

test('a campaign whose template is not active is refused when sent or scheduled', function () {
    Notification::fake();
    $tenant = campaignTenant('northwind');
    campaignCustomer($tenant, 'asha@example.com');
    $campaign = campaignForTenant($tenant, [], templateStatus: 'archived');

    expect(fn () => app(CampaignSender::class)->sendNow($campaign))
        ->toThrow(InvalidArgumentException::class, 'This campaign\'s template is missing or not active.')
        ->and(fn () => app(CampaignSender::class)->schedule($campaign, now()->addDay()))
        ->toThrow(InvalidArgumentException::class);

    Notification::assertNothingSent();
    expect($campaign->fresh()->status)->toBe('draft');
});

test('the send now button reports an inactive template instead of sending', function () {
    Notification::fake();
    $tenant = campaignTenant('northwind');
    $owner = campaignOwner($tenant);
    $campaign = campaignForTenant($tenant, [], templateStatus: 'draft');
    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListEmailCampaigns::class)
        ->callAction(TestAction::make('sendNow')->table($campaign))
        ->assertNotified('This campaign\'s template is missing or not active. Activate the template or pick another one first.');

    expect($campaign->fresh()->status)->toBe('draft');
});

test('a platform campaign reaches active tenants and honors only platform-level unsubscribes', function () {
    $active = campaignTenant('northwind');
    $active->update(['billing_email' => 'billing@northwind.test']);
    campaignTenant('suspended')->update(['status' => 'suspended', 'billing_email' => 'billing@suspended.test']);
    $noBillingEmail = campaignTenant('everest');
    app(TenantContext::class)->wrap($noBillingEmail, fn () => TenantUser::factory()->create(['tenant_id' => $noBillingEmail->id, 'email' => 'owner@everest.test']));
    EmailUnsubscribe::query()->create(['tenant_id' => $active->id, 'email' => 'billing@northwind.test', 'unsubscribed_at' => now()]);

    app(CampaignSender::class)->sendNow(campaignForPlatform('tenants'));

    expect(campaignSentTo())->toBe(['billing@northwind.test', 'owner@everest.test']);
});

test('a platform campaign to SaaS leads can be filtered by status and skips platform unsubscribes', function () {
    SaasLead::query()->create(['name' => 'New Lead', 'email' => 'new@lead.test', 'status' => 'new']);
    SaasLead::query()->create(['name' => 'Lost Lead', 'email' => 'lost@lead.test', 'status' => 'lost']);
    SaasLead::query()->create(['name' => 'Opted Out', 'email' => 'optout@lead.test', 'status' => 'new']);
    EmailUnsubscribe::query()->create(['tenant_id' => null, 'email' => 'optout@lead.test', 'unsubscribed_at' => now()]);

    app(CampaignSender::class)->sendNow(campaignForPlatform('saas_leads', ['lead_statuses' => ['new']]));

    expect(campaignSentTo())->toBe(['new@lead.test']);
});

test('scheduled campaigns are only sent once their time arrives', function () {
    Notification::fake();
    $tenant = campaignTenant('northwind');
    campaignCustomer($tenant, 'asha@example.com');
    $due = campaignForTenant($tenant);
    $later = campaignForTenant($tenant);

    app(CampaignSender::class)->schedule($due, now()->addMinutes(5));
    app(CampaignSender::class)->schedule($later, now()->addDay());

    $this->travel(10)->minutes();
    $this->artisan('app:send-scheduled-campaigns')->expectsOutput('Queued 1 campaign(s).')->assertSuccessful();

    expect($due->fresh()->status)->toBe('sent')
        ->and($later->fresh()->status)->toBe('scheduled');
    expect(fn () => app(CampaignSender::class)->schedule($later, now()->subMinute()))
        ->toThrow(InvalidArgumentException::class, 'A campaign can only be scheduled for a future time.');
});

test('a suspended tenant\'s scheduled campaign is never sent', function () {
    Notification::fake();
    $tenant = campaignTenant('northwind');
    campaignCustomer($tenant, 'asha@example.com');
    $campaign = campaignForTenant($tenant);
    app(CampaignSender::class)->schedule($campaign, now()->addMinutes(5));

    $tenant->update(['status' => 'suspended']);
    $this->travel(10)->minutes();
    app(CampaignSender::class)->dispatchDue();

    Notification::assertNothingSent();
    expect($campaign->fresh()->status)->toBe('failed');
});

test('campaigns are only manageable by their owner and only before sending', function () {
    $tenant = campaignTenant('northwind');
    $owner = campaignOwner($tenant);
    $otherOwner = campaignOwner(campaignTenant('other'));
    $admin = SuperAdmin::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'super_admin', 'team_id' => 0]));
    $tenantCampaign = campaignForTenant($tenant);
    $platformCampaign = campaignForPlatform('tenants');

    expect(Gate::forUser($owner)->allows('update', $tenantCampaign))->toBeTrue()
        ->and(Gate::forUser($otherOwner)->allows('view', $tenantCampaign))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $platformCampaign))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $tenantCampaign))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $platformCampaign))->toBeTrue();

    $tenantCampaign->update(['status' => 'sent']);

    expect(Gate::forUser($owner)->allows('update', $tenantCampaign))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $tenantCampaign))->toBeFalse();
});

test('staff can send a campaign from the list, which only shows their own tenant\'s campaigns', function () {
    Notification::fake();
    $tenant = campaignTenant('northwind');
    $owner = campaignOwner($tenant);
    campaignCustomer($tenant, 'asha@example.com');
    $campaign = campaignForTenant($tenant);
    $foreign = campaignForTenant(campaignTenant('other'));
    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListEmailCampaigns::class)
        ->assertCanSeeTableRecords([$campaign])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callAction(TestAction::make('sendNow')->table($campaign));

    expect($campaign->fresh()->status)->toBe('sent');
    Notification::assertSentOnDemand(CampaignEmail::class, fn (CampaignEmail $email, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'asha@example.com');
});
