<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Modules a subscription plan can switch on or off (admin Billing → Plans → Features). A plan
 * whose `features` column is null predates the toggles and allows everything; a tenant with no
 * subscription at all is also allowed everything — enforcing billing status is a separate concern.
 */
class PlanFeatures
{
    public const CUSTOMER_PORTAL = 'customer_portal';

    public const PUBLIC_API = 'public_api';

    public const ONLINE_PAYMENTS = 'online_payments';

    public const ONLINE_BOOKING = 'online_booking';

    public const EMAIL_CAMPAIGNS = 'email_campaigns';

    public const GIFT_VOUCHERS = 'gift_vouchers';

    public const PROMO_CODES = 'promo_codes';

    public const REPORTS = 'reports';

    /**
     * @var array<string, array{label: string, description: string}>
     */
    public const ALL = [
        self::CUSTOMER_PORTAL => ['label' => 'Customer portal', 'description' => 'Customers can sign in to view bookings, invoices and documents.'],
        self::PUBLIC_API => ['label' => 'Public website API', 'description' => 'Packages, departures, inquiries and waitlist over /api/v1.'],
        self::ONLINE_PAYMENTS => ['label' => 'Online payments', 'description' => 'PayPal / HBL card checkout. "Pay later" stays available.'],
        self::ONLINE_BOOKING => ['label' => 'Online booking', 'description' => 'Travellers book trips and join group departures from the agency\'s public pages.'],
        self::EMAIL_CAMPAIGNS => ['label' => 'Email campaigns', 'description' => 'Mass email to customers or staff.'],
        self::GIFT_VOUCHERS => ['label' => 'Gift vouchers', 'description' => 'Selling and redeeming gift vouchers.'],
        self::PROMO_CODES => ['label' => 'Promo codes', 'description' => 'Managing discount codes.'],
        self::REPORTS => ['label' => 'Reports', 'description' => 'The tenant Reports page.'],
    ];

    public static function allows(?Tenant $tenant, string $feature): bool
    {
        if ($tenant === null) {
            return true;
        }

        $features = $tenant->activeSubscription?->plan?->features;

        if (! is_array($features)) {
            return true;
        }

        return (bool) ($features[$feature] ?? true);
    }

    /**
     * For staff panel and portal resources/pages: the logged-in staff member's or customer's agency.
     */
    public static function allowsCurrentTenant(string $feature): bool
    {
        $user = auth('tenant')->user() ?? auth('customer')->user();

        return static::allows($user?->tenant, $feature);
    }
}
