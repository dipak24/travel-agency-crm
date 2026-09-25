<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PackageResource;
use App\Services\PublicCatalogCache;
use App\Services\PublicInquiry;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function __construct(
        private PublicInquiry $publicInquiry,
        private PublicCatalogCache $cache,
        private TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $payload = $this->cache->remember($this->tenantContext->id(), $request->fullUrl(), function () use ($perPage): array {
            $packages = $this->publicInquiry->publicPackages()
                ->select(['id', 'slug', 'package_code', 'name', 'description', 'duration_days', 'sales_price'])
                ->orderBy('name')
                ->paginate($perPage)
                ->withQueryString();

            return PackageResource::collection($packages)->response()->getData(true);
        });

        return response()->json($payload);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $payload = $this->cache->remember($this->tenantContext->id(), $request->fullUrl(), function () use ($slug): ?array {
            $package = $this->publicInquiry->publicPackages()
                ->where('slug', $slug)
                ->with(['fixedDepartures' => fn ($query) => $query
                    ->whereIn('id', $this->publicInquiry->publicDepartures()->select('id'))
                    ->orderBy('start_date'),
                ])
                ->first();

            $package?->fixedDepartures->each->setRelation('package', $package);

            return $package === null ? null : (new PackageResource($package))->response()->getData(true);
        });

        if ($payload === null) {
            return response()->json(['message' => 'Package not found.'], 404);
        }

        return response()->json($payload);
    }
}
