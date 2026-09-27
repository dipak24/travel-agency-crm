<?php

namespace App\Http\Middleware;

use App\Models\PublicLeadPage;
use App\Models\Tenant;
use App\Support\PlanFeatures;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for an unauthenticated public API request — from the subdomain under
 * `config('app.public_domain')` first, falling back to a `?tenant=slug` query parameter. Suspended
 * and soft-deleted tenants, and tenants whose plan switches off the public API, are treated as not
 * found, so a suspended agency's catalog disappears from the public API the moment it's suspended.
 */
class ResolvePublicTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->subdomainSlug($request) ?? $request->query('tenant');

        $tenant = is_string($slug) && $slug !== ''
            ? Tenant::query()->where('slug', $slug)->where('status', '!=', 'suspended')->first()
            : $this->customDomainTenant($request);

        // A plan without the public API behaves exactly like an unknown agency.
        if ($tenant === null || ! PlanFeatures::allows($tenant, PlanFeatures::PUBLIC_API)) {
            return response()->json(['message' => 'Tenant not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->tenantContext->set($tenant);

        return $next($request);
    }

    /**
     * Cleared here rather than in a `finally` around `$next()` — see ResolveTenant::terminate().
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }

    /**
     * An agency's own domain (e.g. trips.youragency.com) — only once its DNS TXT record has been
     * verified and its website is published, so an unverified claim on a domain is never served.
     */
    private function customDomainTenant(Request $request): ?Tenant
    {
        $page = PublicLeadPage::query()
            ->withoutGlobalScopes()
            ->where('custom_domain', Str::lower($request->getHost()))
            ->whereNotNull('domain_verified_at')
            ->where('is_active', true)
            ->first();

        return $page === null
            ? null
            : Tenant::query()->whereKey($page->tenant_id)->where('status', '!=', 'suspended')->first();
    }

    private function subdomainSlug(Request $request): ?string
    {
        $publicDomain = config('app.public_domain');

        if (! is_string($publicDomain) || $publicDomain === '') {
            return null;
        }

        $host = Str::lower($request->getHost());
        $suffix = '.'.Str::lower($publicDomain);

        if (! Str::endsWith($host, $suffix)) {
            return null;
        }

        $subdomain = Str::beforeLast($host, $suffix);

        return $subdomain !== '' && ! Str::contains($subdomain, '.') ? $subdomain : null;
    }
}
