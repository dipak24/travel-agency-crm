<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for an unauthenticated public API request — from the subdomain under
 * `config('app.public_domain')` first, falling back to a `?tenant=slug` query parameter. Suspended
 * and soft-deleted tenants are treated as not found, so a suspended agency's catalog disappears
 * from the public API the moment it's suspended.
 */
class ResolvePublicTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->subdomainSlug($request) ?? $request->query('tenant');

        $tenant = is_string($slug) && $slug !== ''
            ? Tenant::query()->where('slug', $slug)->where('status', '!=', 'suspended')->first()
            : null;

        if ($tenant === null) {
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
