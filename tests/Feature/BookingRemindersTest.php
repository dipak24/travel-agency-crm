<?php

use App\Filament\Tenant\Pages\ReminderSettings;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reminder;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\BookingReminder;
use App\Services\BookingReminders;
use App\Support\TenantContext;
use App\Support\TransactionalEmailTypes;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @param  array<string, array{enabled: bool, days_before: list<int>}>  $settings
 */
function remindersTenant(array $settings): Tenant
{
    return Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind', 'reminder_settings' => $settings]);
}

function remindersBooking(Tenant $tenant, CarbonImmutable $startDate, array $attributes = []): Booking
{
    return app(TenantContext::class)->wrap($tenant, fn (): Booking => Booking::query()->create([
        'customer_id' => Customer::factory()->create()->id,
        'trip_name' => 'Everest Base Camp',
        'start_date' => $startDate->toDateString(),
        'pax_count' => 1,
        'status' => 'confirmed',
        ...$attributes,
    ]));
}

function remindersCustomer(Booking $booking): Customer
{
    return Customer::query()->withoutGlobalScopes()->findOrFail($booking->customer_id);
}

function remindersToday(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-01');
}

test('no reminders are sent until a tenant enables them', function () {
    Notification::fake();
    remindersBooking(Tenant::query()->create(['name' => 'Northwind', 'slug' => 'northwind']), remindersToday()->addDays(7));

    expect(app(BookingReminders::class)->sendDue(remindersToday()))->toBe(0);
    Notification::assertNothingSent();
});

test('a documents reminder is sent once for the closest schedule rule reached', function () {
    Notification::fake();
    $tenant = remindersTenant(['documents' => ['enabled' => true, 'days_before' => [30, 14, 7]]]);
    $booking = remindersBooking($tenant, remindersToday()->addDays(10));

    expect(app(BookingReminders::class)->sendDue(remindersToday()))->toBe(1)
        ->and(app(BookingReminders::class)->sendDue(remindersToday()))->toBe(0);

    Notification::assertSentTo(remindersCustomer($booking), BookingReminder::class, fn (BookingReminder $reminder): bool => $reminder->templateKey === TransactionalEmailTypes::REMINDER_DOCUMENTS
        && $reminder->mergeData['days_until'] === 10);
    expect(Reminder::query()->withoutGlobalScopes()->sole()->only(['booking_id', 'requirement_type', 'reminder_rule', 'status']))
        ->toBe(['booking_id' => $booking->id, 'requirement_type' => 'documents', 'reminder_rule' => 'days_before:14', 'status' => 'sent']);

    expect(app(BookingReminders::class)->sendDue(remindersToday()->addDays(3)))->toBe(1)
        ->and(Reminder::query()->withoutGlobalScopes()->latest('id')->first()->reminder_rule)->toBe('days_before:7');
});

test('the documents reminder is skipped once documents are uploaded, but not when every upload of a type was rejected', function () {
    Notification::fake();
    $tenant = remindersTenant(['documents' => ['enabled' => true, 'days_before' => [14]]]);
    $uploaded = remindersBooking($tenant, remindersToday()->addDays(10));
    $rejected = remindersBooking($tenant, remindersToday()->addDays(10));

    app(TenantContext::class)->wrap($tenant, function () use ($uploaded, $rejected): void {
        $uploaded->documents()->create(['uploaded_by' => 'customer', 'file_path' => 'docs/a.pdf', 'doc_type' => 'passport', 'status' => 'pending']);
        $rejected->documents()->create(['uploaded_by' => 'customer', 'file_path' => 'docs/b.pdf', 'doc_type' => 'passport', 'status' => 'rejected']);
    });

    app(BookingReminders::class)->sendDue(remindersToday());

    Notification::assertNotSentTo(remindersCustomer($uploaded), BookingReminder::class);
    Notification::assertSentTo(remindersCustomer($rejected), BookingReminder::class);
});

test('a traveler details reminder is sent while travelers are missing or incomplete', function () {
    Notification::fake();
    $tenant = remindersTenant(['traveler_info' => ['enabled' => true, 'days_before' => [14]]]);
    $incomplete = remindersBooking($tenant, remindersToday()->addDays(10), ['pax_count' => 2]);
    $complete = remindersBooking($tenant, remindersToday()->addDays(10));

    app(TenantContext::class)->wrap($tenant, function () use ($incomplete, $complete): void {
        $incomplete->travelers()->create(['name' => 'Asha', 'dob' => '1990-01-01', 'passport_no' => 'P123']);
        $complete->travelers()->create(['name' => 'Bikash', 'dob' => '1988-05-05', 'passport_no' => 'P456']);
    });

    app(BookingReminders::class)->sendDue(remindersToday());

    Notification::assertSentTo(remindersCustomer($incomplete), BookingReminder::class);
    Notification::assertNotSentTo(remindersCustomer($complete), BookingReminder::class);
});

test('a balance due reminder includes the outstanding amount and stops once paid', function () {
    Notification::fake();
    $tenant = remindersTenant(['balance_due' => ['enabled' => true, 'days_before' => [14, 7]]]);
    $booking = remindersBooking($tenant, remindersToday()->addDays(10));
    $invoice = app(TenantContext::class)->wrap($tenant, function () use ($booking): Invoice {
        $invoice = Invoice::query()->create([
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id,
            'amount' => 50000, 'total' => 50000, 'currency' => 'USD', 'status' => 'issued',
        ]);
        Payment::query()->create(['invoice_id' => $invoice->id, 'amount' => 20000, 'currency' => 'USD', 'method' => 'cash', 'type' => 'advance', 'status' => 'completed']);

        return $invoice;
    });

    app(BookingReminders::class)->sendDue(remindersToday());

    Notification::assertSentTo(remindersCustomer($booking), BookingReminder::class, fn (BookingReminder $reminder): bool => $reminder->mergeData['balance_due'] === 'USD 300.00');

    app(TenantContext::class)->wrap($tenant, fn () => Payment::query()->create([
        'invoice_id' => $invoice->id, 'amount' => 30000, 'currency' => 'USD', 'method' => 'cash', 'type' => 'final', 'status' => 'completed',
    ]));

    expect(app(BookingReminders::class)->sendDue(remindersToday()->addDays(3)))->toBe(0);
});

test('cancelled bookings and trips beyond the furthest rule get no reminder', function () {
    Notification::fake();
    $tenant = remindersTenant(['documents' => ['enabled' => true, 'days_before' => [14]]]);
    remindersBooking($tenant, remindersToday()->addDays(10), ['status' => 'cancelled']);
    remindersBooking($tenant, remindersToday()->addDays(20));

    expect(app(BookingReminders::class)->sendDue(remindersToday()))->toBe(0);
});

test('the reminder schedule is saved normalized from the settings page', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind', 'slug' => 'northwind']);
    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole(Role::firstOrCreate(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]));
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ReminderSettings::class)
        ->set('data.balance_due.enabled', true)
        ->set('data.balance_due.days_before', ['7', '30', '7'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(BookingReminders::class)->settingsFor($tenant->fresh())['balance_due'])
        ->toBe(['enabled' => true, 'days_before' => [30, 7]]);

    Livewire::actingAs($owner, 'tenant')->test(ReminderSettings::class)
        ->set('data.balance_due.days_before', ['0'])
        ->call('save')
        ->assertHasFormErrors(['balance_due.days_before.0']);
});

test('the scheduled command sends due reminders', function () {
    Notification::fake();
    $tenant = remindersTenant(['documents' => ['enabled' => true, 'days_before' => [14]]]);
    remindersBooking($tenant, CarbonImmutable::today()->addDays(10));

    $this->artisan('app:send-booking-reminders')->expectsOutput('Sent 1 booking reminder(s).')->assertSuccessful();
});
