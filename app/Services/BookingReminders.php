<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingTraveler;
use App\Models\Invoice;
use App\Models\Reminder;
use App\Models\Tenant;
use App\Notifications\BookingReminder;
use App\Services\Mail\TenantMailer;
use App\Support\Money;
use App\Support\TenantContext;
use App\Support\TransactionalEmailTypes;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Automated customer reminders ahead of a trip, on a schedule each tenant configures
 * (App\Filament\Tenant\Pages\ReminderSettings, stored in `tenants.reminder_settings`):
 *
 * - documents: the booking has no documents yet, or every upload of some document type was rejected
 * - traveler_info: fewer travelers than `pax_count`, or a traveler missing date of birth/passport number
 * - balance_due: an issued/partially paid/overdue invoice on the booking still has a balance
 *
 * A schedule is a list of "days before the trip starts". Each run sends at most one reminder per
 * booking and type — for the closest rule the booking has already reached — and never repeats a
 * rule that was sent before. A run that was missed for a few days therefore catches up with one
 * email, not a burst of every rule that passed in the meantime. Every attempt is logged in
 * `reminders`, which is also the de-duplication record.
 */
class BookingReminders
{
    public const DOCUMENTS = 'documents';

    public const TRAVELER_INFO = 'traveler_info';

    public const BALANCE_DUE = 'balance_due';

    /**
     * Reminder type => transactional email template key.
     */
    public const TEMPLATES = [
        self::DOCUMENTS => TransactionalEmailTypes::REMINDER_DOCUMENTS,
        self::TRAVELER_INFO => TransactionalEmailTypes::REMINDER_TRAVELER_INFO,
        self::BALANCE_DUE => TransactionalEmailTypes::REMINDER_BALANCE_DUE,
    ];

    /**
     * Every reminder type is off until the tenant opts in, so enabling this feature never starts
     * emailing an existing tenant's customers unannounced.
     *
     * @return array<string, array{enabled: bool, days_before: list<int>}>
     */
    public static function defaults(): array
    {
        return [
            self::DOCUMENTS => ['enabled' => false, 'days_before' => [30, 14, 7]],
            self::TRAVELER_INFO => ['enabled' => false, 'days_before' => [30, 14]],
            self::BALANCE_DUE => ['enabled' => false, 'days_before' => [14, 7, 1]],
        ];
    }

    /**
     * @return array<string, array{enabled: bool, days_before: list<int>}>
     */
    public function settingsFor(Tenant $tenant): array
    {
        $stored = $tenant->reminder_settings ?? [];

        return collect(self::defaults())
            ->map(fn (array $default, string $type): array => [
                'enabled' => (bool) ($stored[$type]['enabled'] ?? $default['enabled']),
                'days_before' => collect($stored[$type]['days_before'] ?? $default['days_before'])
                    ->map(fn (mixed $days): int => (int) $days)
                    ->filter(fn (int $days): bool => $days >= 1 && $days <= 365)
                    ->unique()
                    ->sortDesc()
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * Sends every reminder due today across all active tenants. Returns how many were sent.
     */
    public function sendDue(?CarbonInterface $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return Tenant::query()
            ->where('status', '!=', 'suspended')
            ->get()
            ->sum(fn (Tenant $tenant): int => app(TenantContext::class)->wrap(
                $tenant,
                fn (): int => $this->sendDueForTenant($tenant, $today),
            ));
    }

    private function sendDueForTenant(Tenant $tenant, CarbonInterface $today): int
    {
        $settings = collect($this->settingsFor($tenant))
            ->filter(fn (array $setting): bool => $setting['enabled'] && $setting['days_before'] !== []);

        if ($settings->isEmpty()) {
            return 0;
        }

        $furthestRule = $settings->max(fn (array $setting): int => max($setting['days_before']));
        $sent = 0;

        Booking::query()
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereNotNull('customer_id')
            ->whereDate('start_date', '>', $today)
            ->whereDate('start_date', '<=', $today->copy()->addDays($furthestRule))
            ->with(['customer', 'travelers', 'documents', 'invoices'])
            ->each(function (Booking $booking) use ($settings, $today, &$sent): void {
                $daysUntil = (int) $today->diffInDays($booking->start_date);

                foreach ($settings as $type => $setting) {
                    $rule = collect($setting['days_before'])->filter(fn (int $days): bool => $daysUntil <= $days)->min();

                    if ($rule === null || ! $this->isOutstanding($type, $booking)) {
                        continue;
                    }

                    $sent += (int) $this->sendOnce($booking, $type, "days_before:{$rule}", $daysUntil);
                }
            });

        return $sent;
    }

    private function isOutstanding(string $type, Booking $booking): bool
    {
        return match ($type) {
            self::DOCUMENTS => $booking->documents->isEmpty()
                || $booking->documents->groupBy('doc_type')->contains(
                    fn ($documentsOfType): bool => $documentsOfType->every(fn (BookingDocument $document): bool => $document->status === 'rejected'),
                ),
            self::TRAVELER_INFO => $booking->travelers->count() < $booking->pax_count
                || $booking->travelers->contains(
                    fn (BookingTraveler $traveler): bool => $traveler->dob === null || blank($traveler->passport_no),
                ),
            self::BALANCE_DUE => $this->outstandingBalance($booking) > 0,
        };
    }

    private function outstandingBalance(Booking $booking): int
    {
        return (int) $this->openInvoices($booking)->sum(fn (Invoice $invoice): int => max(0, $invoice->balanceDue()));
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function openInvoices(Booking $booking): Collection
    {
        return $booking->invoices->whereIn('status', ['issued', 'partially_paid', 'overdue']);
    }

    private function sendOnce(Booking $booking, string $type, string $rule, int $daysUntil): bool
    {
        $alreadySent = Reminder::query()
            ->where('booking_id', $booking->id)
            ->where('requirement_type', $type)
            ->where('reminder_rule', $rule)
            ->where('status', 'sent')
            ->exists();

        if ($alreadySent) {
            return false;
        }

        $mergeData = [
            'customer_name' => $booking->customer->name,
            'trip_name' => $booking->trip_name,
            'start_date' => $booking->start_date->toFormattedDateString(),
            'days_until' => $daysUntil,
            'portal_url' => url('/portal'),
        ];

        if ($type === self::BALANCE_DUE) {
            $mergeData['balance_due'] = Money::format(
                $this->outstandingBalance($booking),
                $this->openInvoices($booking)->first()?->currency ?? '',
            );
        }

        $delivered = app(TenantMailer::class)->send(
            $booking->tenant_id,
            $booking->customer,
            new BookingReminder($booking, self::TEMPLATES[$type], $mergeData),
        );

        Reminder::query()->create([
            'booking_id' => $booking->id,
            'requirement_type' => $type,
            'recipient_type' => 'customer',
            'channel' => 'email',
            'reminder_rule' => $rule,
            'sent_at' => $delivered ? now() : null,
            'status' => $delivered ? 'sent' : 'failed',
        ]);

        return $delivered;
    }
}
