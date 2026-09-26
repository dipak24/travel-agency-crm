<?php

namespace App\Services\Auth;

use App\Models\Customer;
use App\Notifications\CustomerEmailChangeNotice;
use App\Notifications\CustomerEmailChangeVerification;
use App\Services\Mail\TenantMailer;
use App\Support\AgencySubdomain;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * A verified customer's email never changes directly — not by the customer, not by staff. The new
 * address is parked in `pending_email`, and only replaces `email` once someone opens the signed
 * link emailed to that new address. The current address is told about the request.
 *
 * The link is single-use: it is bound to this exact request (hash of pending email + request
 * time), and confirming clears `pending_email`, so the same link can't be replayed. A newer request
 * replaces the older one and invalidates its link.
 */
class CustomerEmailChange
{
    public const EXPIRE_MINUTES = 60;

    public function __construct(private TenantMailer $mailer, private AccountSetupLinks $links) {}

    /**
     * @throws TooManyAccountLinkRequests
     */
    public function request(Customer $customer, string $newEmail): bool
    {
        $this->links->throttle('email-change:'.$customer->getKey());

        $customer->forceFill([
            'pending_email' => $newEmail,
            'pending_email_requested_at' => now(),
        ])->save();

        $url = AgencySubdomain::within($customer->tenant, fn (): string => URL::temporarySignedRoute(
            'portal.email-change.verify',
            now()->addMinutes(self::EXPIRE_MINUTES),
            ['customer' => $customer->getKey(), 'hash' => $this->requestHash($customer)],
        ));

        $sent = $this->mailer->send(
            $customer->tenant_id,
            Notification::route('mail', $newEmail),
            new CustomerEmailChangeVerification($customer, $url, self::EXPIRE_MINUTES),
        );

        $this->mailer->send($customer->tenant_id, $customer, new CustomerEmailChangeNotice($newEmail));

        return $sent;
    }

    /**
     * Applies the pending change if `$hash` matches the customer's current request. Returns false
     * for a stale/used link, or if the new address was taken by another customer in the meantime.
     */
    public function confirm(Customer $customer, string $hash): bool
    {
        if ($customer->pending_email === null || ! hash_equals($this->requestHash($customer), $hash)) {
            return false;
        }

        $newEmail = $customer->pending_email;
        $oldEmail = $customer->email;

        $taken = Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $customer->tenant_id)
            ->where('email', $newEmail)
            ->whereKeyNot($customer->getKey())
            ->exists();

        if ($taken) {
            return false;
        }

        $customer->forceFill([
            'email' => $newEmail,
            'email_verified_at' => now(),
            'pending_email' => null,
            'pending_email_requested_at' => null,
        ])->save();

        activity()
            ->performedOn($customer)
            ->event('email_changed')
            ->withProperties(['old_email' => $oldEmail, 'new_email' => $newEmail])
            ->log('Customer email address changed');

        return true;
    }

    public function cancel(Customer $customer): void
    {
        $customer->forceFill(['pending_email' => null, 'pending_email_requested_at' => null])->save();
    }

    private function requestHash(Customer $customer): string
    {
        return sha1($customer->pending_email.'|'.$customer->pending_email_requested_at?->getTimestamp());
    }
}
