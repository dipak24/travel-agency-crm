<?php

use App\Filament\Tenant\Resources\EmailTemplateResource\Pages\CreateEmailTemplate;
use App\Filament\Tenant\Resources\EmailTemplateResource\Pages\EditEmailTemplate;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\BookingStatusChanged;
use App\Notifications\CustomerAccountLink;
use App\Services\Mail\EmailTemplates;
use App\Services\TenantOnboarding;
use App\Support\TenantContext;
use App\Support\TransactionalEmailTypes;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function templatesTenant(string $slug): Tenant
{
    return Tenant::query()->create(['name' => ucfirst($slug).' Travel', 'slug' => $slug]);
}

function templatesOwner(Tenant $tenant): TenantUser
{
    return app(TenantContext::class)->wrap($tenant, function () use ($tenant): TenantUser {
        $owner = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
        $owner->assignRole(Role::firstOrCreate(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]));

        return $owner;
    });
}

function templatesTransactional(Tenant $tenant, string $key): EmailTemplate
{
    return EmailTemplate::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->whereHas('type', fn ($query) => $query->where('key', $key))
        ->sole();
}

function templatesStatusMail(Tenant $tenant, string $customerName = 'Asha Gurung'): array
{
    return app(TenantContext::class)->wrap($tenant, function () use ($customerName): array {
        $customer = Customer::factory()->create(['name' => $customerName]);
        $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp', 'status' => 'confirmed']);
        $mail = (new BookingStatusChanged($booking))->toMail($customer);

        return ['subject' => $mail->subject, 'html' => $mail->viewData['html']];
    });
}

test('onboarding a tenant seeds one active transactional template per type', function () {
    $plan = SubscriptionPlan::query()->create(['name' => 'Starter', 'price' => 0, 'billing_cycle' => 'monthly']);

    $tenant = app(TenantOnboarding::class)->create(
        ['name' => 'Northwind Travel', 'slug' => 'northwind'],
        ['name' => 'Owner', 'email' => 'owner@northwind.test', 'password' => 'password'],
        $plan,
    );

    $templates = EmailTemplate::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();

    expect($templates)->toHaveCount(count(TransactionalEmailTypes::all()))
        ->and($templates->pluck('category')->unique()->all())->toBe([EmailTemplate::CATEGORY_TRANSACTIONAL])
        ->and($templates->pluck('status')->unique()->all())->toBe(['active']);

    app(EmailTemplates::class)->seedTenantTemplates($tenant);

    expect(EmailTemplate::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe($templates->count());
});

test('a tenant\'s edited transactional template is used and merge values are escaped', function () {
    $tenant = templatesTenant('northwind');
    app(EmailTemplates::class)->seedTenantTemplates($tenant);
    templatesTransactional($tenant, TransactionalEmailTypes::BOOKING_STATUS_CHANGED)->update([
        'subject' => 'Update on {{ trip_name }}',
        'body_html' => '<p>Namaste {{ customer_name }}, your trip is {{ booking_status }}.</p>',
    ]);

    $mail = templatesStatusMail($tenant, '<script>alert(1)</script>');

    expect($mail['subject'])->toBe('Update on Everest Base Camp')
        ->and($mail['html'])->toBe('<p>Namaste &lt;script&gt;alert(1)&lt;/script&gt;, your trip is confirmed.</p>');
});

test('a transactional template can never be switched off, deleted, or forged', function () {
    $tenant = templatesTenant('northwind');
    app(EmailTemplates::class)->seedTenantTemplates($tenant);
    $template = templatesTransactional($tenant, TransactionalEmailTypes::BOOKING_STATUS_CHANGED);

    $template->update(['subject' => 'Custom subject', 'status' => 'draft']);

    expect($template->fresh()->status)->toBe('active')
        ->and(templatesStatusMail($tenant)['subject'])->toBe('Custom subject')
        ->and(fn () => $template->delete())->toThrow(LogicException::class)
        ->and(fn () => app(TenantContext::class)->wrap($tenant, fn () => EmailTemplate::query()->create([
            'category' => EmailTemplate::CATEGORY_TRANSACTIONAL, 'name' => 'Forged', 'subject' => 'x', 'body_html' => 'x',
        ])))->toThrow(LogicException::class);
});

test('editing a transactional template in the panel only ever changes its wording', function () {
    $tenant = templatesTenant('northwind');
    $owner = templatesOwner($tenant);
    app(EmailTemplates::class)->seedTenantTemplates($tenant);
    $template = templatesTransactional($tenant, TransactionalEmailTypes::BOOKING_STATUS_CHANGED);
    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->assertFormFieldHidden('status')
        ->set('data.subject', 'New wording')
        ->set('data.status', 'archived')
        ->set('data.name', 'Renamed')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($template->fresh()->only(['subject', 'status', 'name']))
        ->toBe(['subject' => 'New wording', 'status' => 'active', 'name' => 'Booking status changed']);
});

test('a marketing template is locked while a campaign using it is scheduled or sending', function () {
    $tenant = templatesTenant('northwind');
    $owner = templatesOwner($tenant);
    $template = app(TenantContext::class)->wrap($tenant, fn (): EmailTemplate => EmailTemplate::query()->create([
        'category' => EmailTemplate::CATEGORY_MARKETING, 'name' => 'Newsletter', 'subject' => 'News', 'body_html' => '<p>Hi</p>', 'status' => 'active',
    ]));
    $campaign = EmailCampaign::query()->create([
        'owner_type' => EmailCampaign::OWNER_TENANT, 'tenant_id' => $tenant->id, 'template_id' => $template->id,
        'name' => 'Spring', 'audience_type' => 'customers', 'status' => 'scheduled',
    ]);

    expect(Gate::forUser($owner)->allows('update', $template))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $template))->toBeFalse()
        ->and(fn () => $template->update(['status' => 'archived']))->toThrow(LogicException::class)
        ->and(fn () => $template->update(['body_html' => '<p>Changed mid-run</p>']))->toThrow(LogicException::class);

    $campaign->update(['status' => 'sent']);
    $template->update(['status' => 'archived']);

    expect($template->fresh()->status)->toBe('archived')
        ->and(Gate::forUser($owner)->allows('update', $template))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $template))->toBeFalse()
        ->and(fn () => $template->delete())->toThrow(LogicException::class);
});

test('another tenant\'s template never affects this tenant\'s emails', function () {
    $other = templatesTenant('other');
    app(EmailTemplates::class)->seedTenantTemplates($other);
    templatesTransactional($other, TransactionalEmailTypes::BOOKING_STATUS_CHANGED)->update(['subject' => 'Other tenant subject']);

    expect(templatesStatusMail(templatesTenant('northwind'))['subject'])->toBe('Your booking "Everest Base Camp" is now confirmed');
});

test('a merge tag the rich text editor URL-encoded inside a link is still substituted', function () {
    $tenant = templatesTenant('northwind');
    app(EmailTemplates::class)->seedTenantTemplates($tenant);
    templatesTransactional($tenant, TransactionalEmailTypes::PORTAL_INVITE)
        ->update(['body_html' => '<p><a href="%7B%7B%20action_url%20%7D%7D">Set password</a></p>']);

    $customer = app(TenantContext::class)->wrap($tenant, fn (): Customer => Customer::factory()->create());
    $mail = (new CustomerAccountLink('https://portal.test/reset?token=abc&email=x', isInvite: true))->toMail($customer);

    expect($mail->viewData['html'])->toBe('<p><a href="https://portal.test/reset?token=abc&amp;email=x">Set password</a></p>');
});

test('a transactional template can be reset to its default wording', function () {
    $tenant = templatesTenant('northwind');
    app(EmailTemplates::class)->seedTenantTemplates($tenant);
    $template = templatesTransactional($tenant, TransactionalEmailTypes::BOOKING_STATUS_CHANGED);
    $template->update(['subject' => 'Changed', 'body_html' => '<p>Changed</p>']);

    app(EmailTemplates::class)->resetToDefault($template);

    $default = TransactionalEmailTypes::all()[TransactionalEmailTypes::BOOKING_STATUS_CHANGED];
    expect($template->fresh()->only(['subject', 'body_html']))->toBe(['subject' => $default['subject'], 'body_html' => $default['body']]);
});

test('transactional templates can be edited but not deleted, marketing templates can be deleted', function () {
    $tenant = templatesTenant('northwind');
    $owner = templatesOwner($tenant);
    app(EmailTemplates::class)->seedTenantTemplates($tenant);
    $transactional = templatesTransactional($tenant, TransactionalEmailTypes::BOOKING_STATUS_CHANGED);
    $marketing = app(TenantContext::class)->wrap($tenant, fn (): EmailTemplate => EmailTemplate::query()->create([
        'category' => EmailTemplate::CATEGORY_MARKETING, 'name' => 'Newsletter', 'subject' => 'News', 'body_html' => '<p>Hi</p>',
    ]));

    expect(Gate::forUser($owner)->allows('update', $transactional))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $transactional))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $marketing))->toBeTrue()
        ->and(Gate::forUser(templatesOwner(templatesTenant('other')))->allows('update', $marketing))->toBeFalse();
});

test('staff without the communications permission cannot open email templates', function () {
    $tenant = templatesTenant('northwind');
    $staff = app(TenantContext::class)->wrap($tenant, fn (): TenantUser => TenantUser::factory()->create(['tenant_id' => $tenant->id]));

    $this->actingAsStaff($staff)->get('/tenant/email-templates')->assertForbidden();
});

test('the template list seeds any missing transactional templates for the tenant', function () {
    $this->seed();
    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();
    $tenant = $owner->tenant;
    EmailTemplate::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->delete();

    $this->actingAsStaff($owner)->get('/tenant/email-templates')->assertOk()->assertSee('Booking status changed');

    expect(EmailTemplate::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())
        ->toBe(count(TransactionalEmailTypes::all()));
});

test('a template created in the panel is always a marketing template', function () {
    $tenant = templatesTenant('northwind');
    $owner = templatesOwner($tenant);
    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreateEmailTemplate::class)
        ->set('data.name', 'Spring offers')
        ->set('data.subject', 'Spring offers for {{ recipient_name }}')
        ->set('data.body_html', '<p>Hello {{ recipient_name }}</p>')
        ->set('data.status', 'active')
        ->call('create')
        ->assertHasNoFormErrors();

    $template = EmailTemplate::query()->withoutGlobalScopes()->where('name', 'Spring offers')->sole();

    expect($template->category)->toBe(EmailTemplate::CATEGORY_MARKETING)
        ->and($template->tenant_id)->toBe($tenant->id)
        ->and($template->email_template_type_id)->toBeNull()
        ->and($template->updated_by)->toBe($owner->id);
});
