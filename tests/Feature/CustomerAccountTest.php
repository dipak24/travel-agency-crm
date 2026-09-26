<?php

use App\Enums\CustomerSpecialDateType;
use App\Enums\CustomerStatus;
use App\Filament\Portal\Pages\Auth\ResetPassword as PortalResetPassword;
use App\Filament\Tenant\Resources\CustomerResource;
use App\Filament\Tenant\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Tenant\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Tenant\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Tenant\Resources\StaffResource\Pages\CreateStaff;
use App\Models\Activity;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\CustomerAccountLink;
use App\Notifications\CustomerEmailChangeNotice;
use App\Notifications\CustomerEmailChangeVerification;
use App\Notifications\StaffAccountLink;
use App\Services\Auth\AccountSetupLinks;
use App\Services\Auth\CustomerEmailChange;
use App\Services\Auth\TooManyAccountLinkRequests;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Seeds permissions/countries and returns a tenant owner acting in the tenant panel, with the
 * tenant context set (see .ai/rules/feature.md).
 */
function customerAccountOwner(): TenantUser
{
    test()->seed();

    $tenant = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('tenant');

    $owner = TenantUser::factory()->create();
    $owner->assignRole(Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail());

    return $owner;
}

/**
 * fillForm() doesn't persist state on this project's Filament/Livewire combo — see .ai/rules/feature.md.
 */
function fillCustomerForm(Testable $test, array $data): Testable
{
    foreach ($data as $key => $value) {
        $test->set("data.{$key}", $value);
    }

    return $test;
}

test('a customer created by staff starts pending and unverified and is emailed a setup link', function () {
    $owner = customerAccountOwner();
    Notification::fake();
    $nepal = Country::query()->where('iso2', 'NP')->value('id');

    $test = Livewire::actingAs($owner, 'tenant')->test(CreateCustomer::class);

    fillCustomerForm($test, [
        'name' => 'Pema Sherpa',
        'email' => 'pema@example.test',
        'type' => 'individual',
        'phone' => '+977 9841000000',
        'whatsapp_same_as_mobile' => true,
        'date_of_birth' => '1990-05-01',
        'nationality_id' => $nepal,
    ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::query()->where('email', 'pema@example.test')->firstOrFail();

    expect($customer->status)->toBe(CustomerStatus::Pending)
        ->and($customer->email_verified_at)->toBeNull()
        ->and($customer->password)->toBeNull()
        ->and($customer->whatsapp_number)->toBe('+977 9841000000')
        ->and($customer->nationality->iso2)->toBe('NP');

    Notification::assertSentTo($customer, CustomerAccountLink::class, fn (CustomerAccountLink $notification): bool => $notification->isInvite);
});

test('the full customer form requires mobile, date of birth and nationality', function () {
    $owner = customerAccountOwner();

    $test = Livewire::actingAs($owner, 'tenant')->test(CreateCustomer::class);

    fillCustomerForm($test, ['name' => 'No Details', 'email' => 'nodetails@example.test'])
        ->call('create')
        ->assertHasFormErrors(['phone' => 'required', 'date_of_birth' => 'required', 'nationality_id' => 'required']);
});

test('the customer form no longer lets staff set a portal password', function () {
    $owner = customerAccountOwner();

    Livewire::actingAs($owner, 'tenant')->test(CreateCustomer::class)
        ->assertFormFieldDoesNotExist('password');
});

test('special dates are saved with the customer', function () {
    $owner = customerAccountOwner();
    $customer = Customer::factory()->create();

    $test = Livewire::actingAs($owner, 'tenant')->test(EditCustomer::class, ['record' => $customer->getKey()]);

    fillCustomerForm($test, [
        'date_of_birth' => '1985-02-02',
        'nationality_id' => Country::query()->where('iso2', 'GB')->value('id'),
        'specialDates' => [
            'first' => ['type' => CustomerSpecialDateType::WeddingAnniversary->value, 'label' => null, 'date' => '2015-06-20', 'remind' => true, 'remind_days_before' => 3],
        ],
    ])
        ->call('save')
        ->assertHasNoFormErrors();

    $specialDate = $customer->specialDates()->sole();

    expect($specialDate->type)->toBe(CustomerSpecialDateType::WeddingAnniversary)
        ->and($specialDate->date->toDateString())->toBe('2015-06-20')
        ->and($specialDate->remind_days_before)->toBe(3)
        ->and($specialDate->tenant_id)->toBe($customer->tenant_id);
});

test('account links are rate limited per account', function () {
    customerAccountOwner();
    Notification::fake();
    $customer = Customer::factory()->pending()->create();
    $links = app(AccountSetupLinks::class);

    $links->sendCustomerLink($customer);

    expect(fn () => $links->sendCustomerLink($customer))->toThrow(TooManyAccountLinkRequests::class);

    Notification::assertSentToTimes($customer, CustomerAccountLink::class, 1);
});

test('staff editing a verified customer cannot change the email directly', function () {
    $owner = customerAccountOwner();
    $customer = Customer::factory()->create(['email' => 'verified@example.test', 'date_of_birth' => '1990-01-01', 'nationality_id' => Country::query()->value('id')]);

    Livewire::actingAs($owner, 'tenant')->test(EditCustomer::class, ['record' => $customer->getKey()])
        ->assertFormFieldDisabled('email')
        ->set('data.email', 'hijacked@example.test')
        ->call('save');

    expect($customer->fresh()->email)->toBe('verified@example.test');
});

test('a verified email only changes once the new address confirms the signed link, which works once', function () {
    customerAccountOwner();
    Notification::fake();
    $customer = Customer::factory()->create(['email' => 'old@example.test']);

    app(CustomerEmailChange::class)->request($customer, 'new@example.test');

    expect($customer->fresh()->email)->toBe('old@example.test');

    Notification::assertSentTo($customer, CustomerEmailChangeNotice::class);
    $url = null;
    Notification::assertSentOnDemand(
        CustomerEmailChangeVerification::class,
        function (CustomerEmailChangeVerification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url): bool {
            $url = $notification->url;

            return $notifiable->routes['mail'] === 'new@example.test';
        },
    );
    app(TenantContext::class)->clear();

    $this->get($url)->assertOk()->assertSee('new@example.test');
    expect($customer->fresh()->email)->toBe('old@example.test');

    $this->post($url)->assertOk()->assertSee('Email address updated');

    $customer->refresh();
    expect($customer->email)->toBe('new@example.test')
        ->and($customer->pending_email)->toBeNull()
        ->and(Activity::query()->where('event', 'email_changed')->where('subject_id', $customer->id)->value('properties')->all())
        ->toMatchArray(['old_email' => 'old@example.test', 'new_email' => 'new@example.test']);

    $this->post($url)->assertOk()->assertSee('no longer valid');
});

test('an email change link with a tampered signature is rejected', function () {
    customerAccountOwner();
    Notification::fake();
    $customer = Customer::factory()->create(['email' => 'old@example.test']);

    app(CustomerEmailChange::class)->request($customer, 'new@example.test');
    app(TenantContext::class)->clear();

    $this->post(portalRoute($customer->tenant, 'portal.email-change.confirm', ['customer' => $customer->id, 'hash' => sha1('new@example.test')]))
        ->assertForbidden();

    expect($customer->fresh()->email)->toBe('old@example.test');
});

test('suspended customers cannot reach the portal and can be reactivated', function () {
    $owner = customerAccountOwner();
    $customer = Customer::factory()->create(['password' => 'secret-password']);

    Livewire::actingAs($owner, 'tenant')->test(ListCustomers::class)
        ->callAction(TestAction::make('suspend')->table($customer));

    $customer->refresh();
    expect($customer->status)->toBe(CustomerStatus::Suspended)
        ->and($customer->canAccessPanel(Filament::getPanel('portal')))->toBeFalse();

    Livewire::actingAs($owner, 'tenant')->test(ListCustomers::class)
        ->callAction(TestAction::make('reactivate')->table($customer));

    expect($customer->fresh()->status)->toBe(CustomerStatus::Active);
});

test('logging in records the customer last login time', function () {
    customerAccountOwner();
    $customer = Customer::factory()->create(['password' => 'secret-password']);

    expect(auth('customer')->attempt(['email' => $customer->email, 'password' => 'secret-password']))->toBeTrue()
        ->and($customer->fresh()->last_login_at)->not->toBeNull();
});

test('the customer list searches name, email and partial mobile, and filters by status, verification and nationality', function () {
    $owner = customerAccountOwner();
    $nepal = Country::query()->where('iso2', 'NP')->value('id');
    $john = Customer::factory()->create(['name' => 'John Walker', 'email' => 'jw@example.test', 'phone' => '+1 202 555 0101']);
    $mobile = Customer::factory()->pending()->create(['name' => 'Asha Rai', 'phone' => '+977 9841234567', 'nationality_id' => $nepal]);

    $list = Livewire::actingAs($owner, 'tenant')->test(ListCustomers::class);

    $list->searchTable('9841')->assertCanSeeTableRecords([$mobile])->assertCanNotSeeTableRecords([$john]);
    $list->searchTable('john')->assertCanSeeTableRecords([$john])->assertCanNotSeeTableRecords([$mobile]);
    $list->searchTable('jw@example')->assertCanSeeTableRecords([$john])->assertCanNotSeeTableRecords([$mobile]);
    $list->searchTable(null);

    $list->filterTable('status', CustomerStatus::Pending->value)->assertCanSeeTableRecords([$mobile])->assertCanNotSeeTableRecords([$john]);
    $list->resetTableFilters()->filterTable('email_verified', true)->assertCanSeeTableRecords([$john])->assertCanNotSeeTableRecords([$mobile]);
    $list->resetTableFilters()->filterTable('nationality_id', $nepal)->assertCanSeeTableRecords([$mobile])->assertCanNotSeeTableRecords([$john]);
});

test('clicking a customer row opens the view page, which other tenants cannot open', function () {
    $owner = customerAccountOwner();
    $customer = Customer::factory()->create(['name' => 'Viewable Customer']);

    $table = Livewire::actingAs($owner, 'tenant')->test(ListCustomers::class)->instance()->getTable();

    expect($table->getRecordUrl($customer))->toBe(CustomerResource::getUrl('view', ['record' => $customer]));

    $this->actingAsStaff($owner)->get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertOk()
        ->assertSee('Viewable Customer')
        ->assertSee('Never logged in');

    $otherTenant = Tenant::factory()->create();
    $outsider = app(TenantContext::class)->wrap($otherTenant, fn (): TenantUser => TenantUser::factory()->create());
    app(TenantContext::class)->clear();

    $this->flushSession();

    $this->actingAsStaff($outsider)->get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertNotFound();
});

test('new staff are emailed a setup link instead of being given a password', function () {
    $owner = customerAccountOwner();
    Notification::fake();

    $test = Livewire::actingAs($owner, 'tenant')->test(CreateStaff::class)
        ->assertFormFieldDoesNotExist('password');

    fillCustomerForm($test, ['name' => 'New Guide', 'email' => 'guide@example.test', 'status' => 'active'])
        ->call('create')
        ->assertHasNoFormErrors();

    $staff = TenantUser::query()->where('email', 'guide@example.test')->firstOrFail();

    expect($staff->password)->toBeNull();
    Notification::assertSentTo($staff, StaffAccountLink::class, fn (StaffAccountLink $notification): bool => $notification->isInvite);
});

test('a setup link only resets the customer of the agency that sent it when the same email exists at two agencies', function () {
    customerAccountOwner();
    Notification::fake();
    $agencyA = Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
    $agencyB = Tenant::factory()->create();

    $bCustomer = app(TenantContext::class)->wrap($agencyB, fn (): Customer => Customer::factory()->pending()->create(['email' => 'shared@example.test']));
    $aCustomer = app(TenantContext::class)->wrap($agencyA, fn (): Customer => Customer::factory()->pending()->create(['email' => 'shared@example.test']));

    app(AccountSetupLinks::class)->sendCustomerLink($aCustomer);
    $url = Notification::sent($aCustomer, CustomerAccountLink::class)->first()->url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect((int) $query['tenant'])->toBe($agencyA->id);

    app(TenantContext::class)->clear();
    Filament::setCurrentPanel('portal');

    Livewire::withQueryParams(['tenant' => $query['tenant']])
        ->test(PortalResetPassword::class, ['email' => $query['email'], 'token' => $query['token']])
        ->set('password', 'a-new-password')
        ->set('passwordConfirmation', 'a-new-password')
        ->call('resetPassword');

    $aCustomer = Customer::query()->withoutGlobalScopes()->find($aCustomer->id);
    $bCustomer = Customer::query()->withoutGlobalScopes()->find($bCustomer->id);

    expect($aCustomer->password)->not->toBeNull()
        ->and($aCustomer->status)->toBe(CustomerStatus::Active)
        ->and($bCustomer->password)->toBeNull()
        ->and($bCustomer->status)->toBe(CustomerStatus::Pending);
});

test('changing the agency in a signed password link is rejected', function () {
    customerAccountOwner();
    Notification::fake();
    $customer = Customer::factory()->pending()->create();

    app(AccountSetupLinks::class)->sendCustomerLink($customer);
    $url = Notification::sent($customer, CustomerAccountLink::class)->first()->url;
    $tampered = preg_replace('/tenant=\d+/', 'tenant=999', $url);

    app(TenantContext::class)->clear();

    $this->get($url)->assertOk();
    $this->get($tampered)->assertForbidden();
});
