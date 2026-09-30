<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\FixedDeparture;
use App\Models\Tenant;
use App\Services\InvoicePaymentLinks;
use App\Services\PublicBooking;
use App\Services\PublicInquiry;
use App\Support\PublicSite;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Public group joining (no login): every upcoming fixed departure of the agency's published
 * packages, and a form to take seats on one. Seats offered exclude the overbooking buffer, which
 * stays for staff; a full departure points to the waitlist instead.
 */
class DepartureController extends Controller
{
    public function __construct(private PublicInquiry $publicInquiry) {}

    public function index(): View
    {
        $this->ensureEnabled();

        return view('public-site.departures.index', [
            'departures' => $this->publicInquiry->publicDepartures()->with('package')->orderBy('start_date')->get(),
        ]);
    }

    /**
     * The booking link the agency puts on its marketing site; `?pax=2` pre-fills the travellers.
     */
    public function show(Request $request, int $departure): View
    {
        $this->ensureEnabled();
        $departureModel = $this->findDeparture($departure);

        return view('public-site.departures.join', [
            'departure' => $departureModel,
            'package' => $departureModel->package,
            'depositPercent' => PublicSite::depositPercent($this->tenant()),
            'prefill' => PublicSite::prefill($request, $departureModel->publicSeatsRemaining()),
        ]);
    }

    public function join(Request $request, int $departure): RedirectResponse
    {
        $this->ensureEnabled();
        $departureModel = $this->findDeparture($departure);

        abort_if($departureModel->publicSeatsRemaining() < 1, 404);

        $data = $request->validate([
            ...PackageBookingController::travellerRules(),
            'pax_count' => ['required', 'integer', 'min:1', 'max:'.$departureModel->publicSeatsRemaining()],
        ], ['pax_count.max' => 'Only :max seats are left on this departure.']);

        $invoice = app(PublicBooking::class)->book($departureModel->package, $departureModel, $data);

        return redirect()->away(app(InvoicePaymentLinks::class)->url($invoice));
    }

    private function findDeparture(int $departureId): FixedDeparture
    {
        return $this->publicInquiry->publicDepartures()
            ->with(['package.includeExcludeItems.includeExclude', 'package.services' => fn ($query) => $query->where('is_active', true)])
            ->findOrFail($departureId);
    }

    private function ensureEnabled(): void
    {
        abort_unless(PublicSite::allows($this->tenant(), PublicSite::GROUPS), 404);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }
}
