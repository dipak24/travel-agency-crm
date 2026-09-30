<?php

namespace App\Support;

use App\Models\PublicLeadPage;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Which of an agency's public pages (on its own subdomain) are live. Every page needs the agency's
 * website to be published (tenant Website settings) and its own switch there; trip booking and
 * group joining also need the plan's "Online booking" feature, and gift vouchers the plan's
 * "Gift vouchers" feature.
 */
class PublicSite
{
    public const TRIPS = 'trip_booking';

    public const GROUPS = 'group_joining';

    public const GIFTS = 'gift_vouchers';

    /**
     * Memoized on the current request, since a single public page asks for it several times.
     */
    public static function page(Tenant $tenant): ?PublicLeadPage
    {
        $attributes = request()->attributes;
        $key = 'public-site.page.'.$tenant->getKey();

        if (! $attributes->has($key)) {
            $attributes->set($key, PublicLeadPage::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->first());
        }

        return $attributes->get($key);
    }

    public static function allows(Tenant $tenant, string $section): bool
    {
        $page = static::page($tenant);

        if ($page === null || ! $page->is_active || ! $page->bookingSettings()[$section]) {
            return false;
        }

        return PlanFeatures::allows($tenant, $section === self::GIFTS ? PlanFeatures::GIFT_VOUCHERS : PlanFeatures::ONLINE_BOOKING);
    }

    /**
     * The agency's terms and conditions page on its marketing site, if set in Website settings.
     */
    public static function termsUrl(Tenant $tenant): ?string
    {
        $url = static::page($tenant)?->contact_settings['terms_url'] ?? null;

        return is_string($url) && preg_match('/^https?:\/\//i', $url) === 1 ? $url : null;
    }

    public static function depositPercent(Tenant $tenant): int
    {
        return (static::page($tenant) ?? new PublicLeadPage)->bookingSettings()['deposit_percent'];
    }

    /**
     * Form defaults a booking link can carry from the agency's marketing site, e.g.
     * `?start_date=2026-12-01&pax=2`. Anything unusable is dropped rather than reported, and `pax`
     * is clamped to what the trip allows.
     *
     * @return array{start_date: ?string, pax: ?int}
     */
    public static function prefill(Request $request, int $maxPax, bool $withStartDate = false): array
    {
        $startDate = $request->query('start_date');
        $pax = $request->query('pax');

        $startDate = $withStartDate && is_string($startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $startDate)
            : null;

        return [
            'start_date' => $startDate instanceof CarbonImmutable && $startDate->toDateString() === $request->query('start_date') && $startDate->isAfter(today())
                ? $startDate->toDateString()
                : null,
            'pax' => is_string($pax) && ctype_digit($pax) && (int) $pax >= 1
                ? min((int) $pax, max(1, $maxPax))
                : null,
        ];
    }
}
