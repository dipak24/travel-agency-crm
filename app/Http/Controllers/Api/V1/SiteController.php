<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SiteResource;
use App\Models\PublicLeadPage;
use App\Services\PublicCatalogCache;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SiteController extends Controller
{
    public function __construct(
        private PublicCatalogCache $cache,
        private TenantContext $tenantContext,
    ) {}

    /**
     * The agency's published website settings; 404 until the agency publishes its site.
     */
    public function show(Request $request): JsonResponse
    {
        $payload = $this->cache->remember($this->tenantContext->id(), $request->fullUrl(), function () use ($request): ?array {
            $page = PublicLeadPage::query()->with('tenant')->where('is_active', true)->first();

            return $page === null ? null : (new SiteResource($page))->toArray($request);
        });

        if ($payload === null) {
            return response()->json(['message' => 'This agency has not published its website.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $payload]);
    }
}
