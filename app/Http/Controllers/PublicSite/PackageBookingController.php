<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Tenant;
use App\Services\InvoicePaymentLinks;
use App\Services\PublicBooking;
use App\Services\PublicInquiry;
use App\Support\PublicSite;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Booking a published package on the traveller's own dates (on the agency's subdomain, no login).
 * The agency's marketing site links here — `/book/{package code}?start_date=2026-12-01&pax=2` pre-fills
 * the form — so this page is only the booking form with a short trip summary, not a catalog.
 */
class PackageBookingController extends Controller
{
    /**
     * Own-dates bookings have no package-level traveller limit (that only applies to fixed
     * departures), so the form just caps the count at a sane size.
     */
    public const MAX_PAX = 50;

    public function __construct(private PublicInquiry $publicInquiry) {}

    /**
     * The booking page without a package in the link: the traveller picks any published package
     * first (`?trip={package code}`), then books it on its own page.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $this->ensureEnabled();

        $trip = $request->query('trip');

        if (is_string($trip) && $this->publicInquiry->publicPackages()->where('package_code', $trip)->exists()) {
            return redirect()->route('public.book.show', [$trip, ...$request->only(['start_date', 'pax'])]);
        }

        return view('public-site.book.index', [
            'packages' => $this->publicInquiry->publicPackages()->orderBy('name')->get(['name', 'package_code', 'duration_days', 'sales_price']),
        ]);
    }

    public function show(Request $request, string $code): View
    {
        $this->ensureEnabled();
        $package = $this->findPackage($code);

        return view('public-site.book.show', [
            'package' => $package,
            'maxPax' => self::MAX_PAX,
            'depositPercent' => PublicSite::depositPercent($this->tenant()),
            'prefill' => PublicSite::prefill($request, self::MAX_PAX, withStartDate: true),
        ]);
    }

    public function store(Request $request, string $code): RedirectResponse
    {
        $this->ensureEnabled();
        $package = $this->findPackage($code);

        $data = $request->validate([
            ...self::travellerRules(),
            'pax_count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PAX],
            'start_date' => ['required', 'date', 'after:today'],
        ], ['start_date.after' => 'Choose a start date in the future.']);

        $invoice = app(PublicBooking::class)->book($package, null, $data);

        return redirect()->away(app(InvoicePaymentLinks::class)->url($invoice, offerPayLater: true));
    }

    /**
     * Lets the booking form (own dates or a group departure of this package) check a promo code
     * or gift voucher before the traveller books.
     */
    public function checkCode(Request $request, string $code): JsonResponse
    {
        abort_unless(PublicSite::allows($this->tenant(), PublicSite::TRIPS) || PublicSite::allows($this->tenant(), PublicSite::GROUPS), 404);
        $package = $this->findPackage($code);

        $data = $request->validate([
            'kind' => ['required', 'in:promo,voucher'],
            'code' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        return response()->json(app(PublicBooking::class)->checkCode($package, $data['kind'], $data['code'], $data['email'] ?? null));
    }

    /**
     * Rules shared by package booking and group joining. `website` is a honeypot: the field is
     * hidden from people, so only bots fill it in.
     *
     * @return array<string, array<int, string>>
     */
    public static function travellerRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'arrival_date' => ['nullable', 'date', 'after_or_equal:today'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'gift_voucher' => ['nullable', 'string', 'max:50'],
            'extras' => ['nullable', 'array'],
            'extras.*' => ['integer'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['accepted'],
            'website' => ['prohibited'],
        ];
    }

    private function findPackage(string $code): Package
    {
        return $this->publicInquiry->publicPackages()
            ->where('package_code', $code)
            ->with(['includeExcludeItems.includeExclude', 'services' => fn ($query) => $query->where('is_active', true)])
            ->firstOrFail();
    }

    private function ensureEnabled(): void
    {
        abort_unless(PublicSite::allows($this->tenant(), PublicSite::TRIPS), 404);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }
}
