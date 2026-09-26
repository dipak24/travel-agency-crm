<?php

namespace App\Services\Auth;

use App\Models\Customer;
use App\Models\TenantUser;
use App\Notifications\CustomerAccountLink;
use App\Notifications\StaffAccountLink;
use App\Services\Mail\TenantMailer;
use App\Support\AgencySubdomain;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The only way an admin gets someone into an account: email them a single-use link to choose
 * their own password. Nobody ever types a password on another person's behalf.
 *
 * Tokens come from Laravel's password broker, so they are stored hashed in
 * `password_reset_tokens`, expire after `auth.passwords.{broker}.expire` minutes, and are deleted
 * as soon as the password is set (Filament's reset page → PasswordBroker::reset()). A customer's
 * first successful use also verifies their email and activates the account (see the
 * PasswordReset listener in AppServiceProvider → Customer::markAccountActivated()).
 *
 * A user who has never set a password gets the "invite" wording; everyone else gets a reset.
 */
class AccountSetupLinks
{
    /**
     * [max attempts, decay seconds] — at most one email a minute and five an hour per account.
     *
     * @var list<array{0: int, 1: int}>
     */
    private const LIMITS = [[1, 60], [5, 3600]];

    public function __construct(private TenantMailer $mailer) {}

    /**
     * @throws TooManyAccountLinkRequests
     */
    public function sendCustomerLink(Customer $customer): bool
    {
        $this->throttle('customer:'.$customer->getKey());

        $token = Password::broker('customers')->createToken($customer);
        $url = AgencySubdomain::within(
            $customer->tenant,
            fn (): string => Filament::getPanel('portal')->getResetPasswordUrl($token, $customer, ['tenant' => $customer->tenant_id]),
        );

        return $this->mailer->send($customer->tenant_id, $customer, new CustomerAccountLink($url, isInvite: $customer->password === null));
    }

    /**
     * @throws TooManyAccountLinkRequests
     */
    public function sendStaffLink(TenantUser $user): bool
    {
        $this->throttle('tenant_user:'.$user->getKey());

        $token = Password::broker('tenant_users')->createToken($user);
        $url = AgencySubdomain::within(
            $user->tenant,
            fn (): string => Filament::getPanel('tenant')->getResetPasswordUrl($token, $user),
        );

        return $this->mailer->send($user->tenant_id, $user, new StaffAccountLink($url, isInvite: $user->password === null));
    }

    /**
     * Shared by every account email that could be used to spam an inbox (see CustomerEmailChange).
     *
     * @throws TooManyAccountLinkRequests
     */
    public function throttle(string $accountKey): void
    {
        foreach (self::LIMITS as [$maxAttempts, $decaySeconds]) {
            $key = "account-link:{$accountKey}:{$decaySeconds}";

            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw new TooManyAccountLinkRequests(RateLimiter::availableIn($key));
            }
        }

        foreach (self::LIMITS as [, $decaySeconds]) {
            RateLimiter::hit("account-link:{$accountKey}:{$decaySeconds}", $decaySeconds);
        }
    }
}
