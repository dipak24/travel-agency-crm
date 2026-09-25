<?php

use App\Filament\Resources\PlatformEmailTemplateResource\Pages\EditPlatformEmailTemplate;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\PlatformEmailTemplate;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Models\TenantUser;
use App\Notifications\PasswordResetRequested;
use App\Notifications\TestMailSettingNotification;
use App\Services\Mail\CampaignSender;
use App\Services\Mail\EmailTemplates;
use App\Support\SystemEmailTypes;
use App\Support\TenantContext;
use App\Support\TransactionalEmailTypes;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function systemTemplate(string $key): PlatformEmailTemplate
{
    return PlatformEmailTemplate::query()->system()->where('key', $key)->sole();
}

function systemResetMail(object $notifiable): array
{
    $notification = new PasswordResetRequested('token-123');
    $notification->url = 'https://app.test/reset/token-123';
    $mail = $notification->toMail($notifiable);

    return ['subject' => $mail->subject, 'html' => $mail->viewData['html'], 'mailer' => $mail->mailer, 'from' => $mail->from];
}

test('the admin forgot-password email uses the Super Admin\'s edited system template', function () {
    app(EmailTemplates::class)->seedSystemTemplates();
    systemTemplate(SystemEmailTypes::ADMIN_PASSWORD_RESET)->update([
        'subject' => 'Password help for {{ user_name }}',
        'body_html' => '<p>Use <a href="{{ reset_url }}">this link</a> within {{ expire_minutes }} minutes.</p>',
    ]);
    $admin = SuperAdmin::factory()->create(['name' => 'Dipak']);
    Filament::setCurrentPanel('admin');

    Livewire::test(RequestPasswordReset::class)->set('data.email', $admin->email)->call('request');

    $email = Mail::getSymfonyTransport()->messages()->sole()->getOriginalMessage();

    expect($email->getSubject())->toBe('Password help for Dipak')
        ->and($email->getHtmlBody())->toContain('within 60 minutes')->toContain('/admin/password-reset/reset?');
});

test('a system email can never be switched off, deleted, or forged', function () {
    app(EmailTemplates::class)->seedSystemTemplates();
    $template = systemTemplate(SystemEmailTypes::ADMIN_PASSWORD_RESET);

    $template->update(['subject' => 'Custom', 'status' => 'draft']);

    expect($template->fresh()->status)->toBe('active')
        ->and(systemResetMail(SuperAdmin::factory()->create())['subject'])->toBe('Custom')
        ->and(fn () => $template->delete())->toThrow(LogicException::class)
        ->and(fn () => PlatformEmailTemplate::query()->create([
            'category' => SystemEmailTypes::CATEGORY, 'name' => 'Forged', 'subject' => 'x', 'body_html' => 'x',
        ]))->toThrow(LogicException::class);
});

test('the staff reset email names the agency and goes through the agency\'s own SMTP', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    app(TenantContext::class)->wrap($tenant, fn () => TenantMailSetting::query()->create([
        'enabled' => true, 'from_name' => 'Northwind', 'from_address' => 'hello@northwind.test',
        'credentials' => ['host' => 'smtp.northwind.test', 'port' => 587, 'username' => 'u', 'password' => 'p', 'encryption' => 'tls'],
    ]));
    $staff = app(TenantContext::class)->wrap($tenant, fn (): TenantUser => TenantUser::factory()->create(['tenant_id' => $tenant->id]));

    $mail = systemResetMail($staff);

    expect($mail['subject'])->toBe('Reset your Northwind Travel staff password')
        ->and($mail['html'])->toContain('https://app.test/reset/token-123')
        ->and($mail['mailer'])->toBe("tenant_dynamic_{$tenant->id}")
        ->and($mail['from'])->toBe(['hello@northwind.test', 'Northwind'])
        ->and(config('mail.default'))->not->toBe("tenant_dynamic_{$tenant->id}");
});

test('the customer portal reset email uses the tenant\'s own editable template', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    app(EmailTemplates::class)->seedTenantTemplates($tenant);
    EmailTemplate::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
        ->whereHas('type', fn ($query) => $query->where('key', TransactionalEmailTypes::CUSTOMER_PASSWORD_RESET))
        ->sole()
        ->update(['subject' => '{{ tenant_name }} portal: new password for {{ customer_name }}']);
    $customer = app(TenantContext::class)->wrap($tenant, fn (): Customer => Customer::factory()->create(['name' => 'Asha']));

    $mail = systemResetMail($customer);

    expect($mail['subject'])->toBe('Northwind Travel portal: new password for Asha')
        ->and($mail['mailer'])->toBeNull();
});

test('admin Email Templates shows campaign templates and system emails in separate tabs', function () {
    $this->seed();
    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    PlatformEmailTemplate::query()->create([
        'category' => 'newsletter', 'name' => 'Monthly newsletter', 'subject' => 'News', 'body_html' => '<p>Hi</p>', 'status' => 'active',
    ]);

    $this->actingAs($admin, 'super_admin')->get('/admin/email-templates?tab=campaign')
        ->assertOk()
        ->assertSee('Campaign templates')
        ->assertSee('System emails')
        ->assertSee('Monthly newsletter')
        ->assertDontSee('Password reset — tenant staff');

    $this->actingAs($admin, 'super_admin')->get('/admin/email-templates?tab=system')
        ->assertOk()
        ->assertSee('Password reset — tenant staff')
        ->assertSee('SMTP test email')
        ->assertDontSee('Monthly newsletter');
});

test('system emails can be edited and reset from the admin panel, but only their wording', function () {
    $this->seed();
    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();
    $template = systemTemplate(SystemEmailTypes::STAFF_PASSWORD_RESET);
    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin, 'super_admin')->test(EditPlatformEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->assertFormFieldHidden('status')
        ->assertFormFieldHidden('category')
        ->set('data.subject', 'Changed subject')
        ->set('data.status', 'archived')
        ->set('data.category', 'promotion')
        ->call('save')
        ->assertHasNoFormErrors();
    expect($template->fresh()->only(['subject', 'status', 'category']))
        ->toBe(['subject' => 'Changed subject', 'status' => 'active', 'category' => SystemEmailTypes::CATEGORY]);

    Livewire::actingAs($admin, 'super_admin')->test(EditPlatformEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->callAction('resetToDefault');
    expect($template->fresh()->subject)->toBe(SystemEmailTypes::all()[SystemEmailTypes::STAFF_PASSWORD_RESET]['subject'])
        ->and(Gate::forUser($admin)->allows('delete', $template))->toBeFalse();
});

test('an admin with only the marketing permission never sees or edits system emails', function () {
    app(EmailTemplates::class)->seedSystemTemplates();
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    Permission::findOrCreate('manage platform', 'super_admin');
    $marketer = SuperAdmin::factory()->create();
    $marketer->givePermissionTo(Permission::findOrCreate('manage marketing', 'super_admin'));

    $this->actingAs($marketer, 'super_admin')->get('/admin/email-templates')
        ->assertOk()
        ->assertSee('Campaign templates')
        ->assertDontSee('System emails');
    $this->actingAs($marketer, 'super_admin')->get('/admin/email-templates?tab=system')
        ->assertDontSee('Password reset — platform admins');

    expect(Gate::forUser($marketer)->allows('update', systemTemplate(SystemEmailTypes::ADMIN_PASSWORD_RESET)))->toBeFalse();
});

test('tenants never see platform system emails', function () {
    $this->seed();
    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();

    $this->actingAs($owner, 'tenant')->get('/tenant/email-templates')
        ->assertOk()
        ->assertSee('Transactional emails')
        ->assertSee('Campaign templates')
        ->assertDontSee('Password reset — platform admins')
        ->assertDontSee('Password reset — tenant staff');
    $this->actingAs($owner, 'tenant')->get('/admin/email-templates')->assertRedirect();
});

test('the SMTP test email is an editable system email', function () {
    app(EmailTemplates::class)->seedSystemTemplates();
    systemTemplate(SystemEmailTypes::MAIL_SETTINGS_TEST)->update(['subject' => 'SMTP check for {{ sender_name }}']);
    $staff = new class
    {
        public string $name = 'Asha';
    };

    $mail = (new TestMailSettingNotification('Northwind Travel'))->toMail($staff);

    expect($mail->subject)->toBe('SMTP check for Northwind Travel')
        ->and($mail->viewData['html'])->toContain('Hello Asha');
});

test('a system template can never be sent as a platform campaign', function () {
    app(EmailTemplates::class)->seedSystemTemplates();
    $campaign = EmailCampaign::query()->create([
        'owner_type' => EmailCampaign::OWNER_PLATFORM,
        'template_id' => systemTemplate(SystemEmailTypes::ADMIN_PASSWORD_RESET)->id,
        'name' => 'Abuse', 'audience_type' => 'saas_leads', 'status' => 'draft',
    ]);

    expect(fn () => app(CampaignSender::class)->sendNow($campaign))->toThrow(InvalidArgumentException::class)
        ->and($campaign->fresh()->status)->toBe('draft');
});
