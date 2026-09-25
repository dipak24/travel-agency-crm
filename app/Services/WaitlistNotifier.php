<?php

namespace App\Services;

use App\Models\BookingWaitlist;
use App\Models\TenantUser;
use App\Notifications\WaitlistSeatAvailable;
use App\Services\Mail\TenantMailer;
use LogicException;

/**
 * The staff-driven half of the departure waitlist (visitors join it through the public API — see
 * PublicInquiry::joinWaitlist()). Notifying is deliberately manual: staff decide who gets offered
 * a freed-up seat, since capacity can open for reasons (a cancellation, a raised slot count) the
 * app can't judge on its own.
 */
class WaitlistNotifier
{
    /**
     * Emails the waitlisted customer that seats are available. Returns false (and leaves the entry
     * `waiting`) when the email could not be delivered.
     */
    public function notify(BookingWaitlist $entry, TenantUser $staff): bool
    {
        if ($entry->status !== 'waiting') {
            throw new LogicException('Only a waiting waitlist entry can be notified.');
        }

        if ($entry->customer === null) {
            throw new LogicException('This waitlist entry has no customer to notify.');
        }

        $delivered = app(TenantMailer::class)->send($entry->tenant_id, $entry->customer, new WaitlistSeatAvailable($entry));

        if ($delivered) {
            $entry->update([
                'status' => 'notified',
                'notified_at' => now(),
                'notified_by_staff_id' => $staff->id,
            ]);
        }

        return $delivered;
    }
}
