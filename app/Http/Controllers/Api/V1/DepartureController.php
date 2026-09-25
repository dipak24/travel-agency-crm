<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FixedDepartureResource;
use App\Services\PublicCatalogCache;
use App\Services\PublicInquiry;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartureController extends Controller
{
    public function __construct(
        private PublicInquiry $publicInquiry,
        private PublicCatalogCache $cache,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Upcoming departures across all public packages, optionally narrowed with `?package=slug`.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);
        $packageSlug = $request->query('package');

        $payload = $this->cache->remember($this->tenantContext->id(), $request->fullUrl(), function () use ($perPage, $packageSlug): array {
            $departures = $this->publicInquiry->publicDepartures()
                ->with('package:id,slug,sales_price')
                ->when(is_string($packageSlug), fn ($query) => $query->whereIn(
                    'package_id',
                    $this->publicInquiry->publicPackages()->where('slug', $packageSlug)->select('id'),
                ))
                ->orderBy('start_date')
                ->paginate($perPage)
                ->withQueryString();

            return FixedDepartureResource::collection($departures)->response()->getData(true);
        });

        return response()->json($payload);
    }
}
