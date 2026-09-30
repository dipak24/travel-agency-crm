<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FixedDepartureResource;
use App\Models\FixedDeparture;
use App\Services\PublicCatalogCache;
use App\Services\PublicInquiry;
use App\Support\Money;
use App\Support\PublicSite;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data for the departures widget (public/js/departures-widget.js) that the agency embeds on its
 * marketing site: upcoming departures, each with the absolute link to its booking page here.
 * Read from other origins (CORS path `widget/*`), so it only exposes what FixedDepartureResource
 * already makes public. Needs group joining switched on, not the Public API plan feature.
 */
class DepartureWidgetController extends Controller
{
    public function __construct(
        private PublicInquiry $publicInquiry,
        private PublicCatalogCache $cache,
        private TenantContext $tenantContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();

        if (! PublicSite::allows($tenant, PublicSite::GROUPS)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $limit = min(max((int) $request->query('limit', 20), 1), 50);
        $packageCode = $request->query('package');

        $payload = $this->cache->remember($tenant->getKey(), 'widget:'.$request->fullUrl(), function () use ($request, $limit, $packageCode, $tenant): array {
            $departures = $this->publicInquiry->publicDepartures()
                ->with('package:id,slug,name,sales_price')
                ->when(is_string($packageCode) && $packageCode !== '', fn ($query) => $query->whereIn(
                    'package_id',
                    $this->publicInquiry->publicPackages()->where('package_code', $packageCode)->select('id'),
                ))
                ->orderBy('start_date')
                ->limit($limit)
                ->get();

            return [
                'data' => $departures->map(fn (FixedDeparture $departure): array => [
                    ...(new FixedDepartureResource($departure))->toArray($request),
                    'package_name' => $departure->package->name,
                    'price_formatted' => Money::format($departure->perPersonPrice(), $tenant->currency ?? 'USD'),
                    'book_url' => route('public.departures.show', $departure->id),
                ])->all(),
            ];
        });

        return response()->json($payload);
    }
}
