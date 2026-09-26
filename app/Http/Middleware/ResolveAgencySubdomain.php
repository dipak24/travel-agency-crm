<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\AgencySubdomain;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff panel and the customer portal only exist on an agency subdomain
 * ({slug}.{agency.domain}). Resolves that agency for the request (AgencySubdomain + TenantContext).
 * The bare platform domain and unknown slugs get the same 404 "use your agency's link" page, so
 * the response never reveals which agencies exist.
 *
 * Registered as persistent panel middleware, so Livewire re-runs it on every component update —
 * Livewire's replay keeps the real request's host, so the same agency is resolved. State is cleared
 * in terminate(), never in a `finally` around $next() (see .ai/rules/middleware.md).
 */
class ResolveAgencySubdomain
{
    public function __construct(private AgencySubdomain $agencySubdomain, private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = AgencySubdomain::slugFromHost($request->getHost());
        $agency = $slug === null ? null : Tenant::query()->where('slug', $slug)->first();

        if ($agency === null) {
            return response()->view('agency-link-required', [], Response::HTTP_NOT_FOUND);
        }

        $this->agencySubdomain->set($agency);
        $this->tenantContext->set($agency);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->agencySubdomain->set(null);
    }
}
