<?php

namespace App\Support;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\URL;

/**
 * Every agency has its own subdomain — {slug}.{agency.domain} — serving its staff panel (/tenant)
 * and its customer portal (/portal) under its own branding. This holds the agency the current
 * request's subdomain resolved to (set by App\Http\Middleware\ResolveAgencySubdomain); null on the
 * platform's own domain, where only the Super Admin panel lives.
 *
 * Staff and customer logins, "Forgot password" and password links are all scoped to this agency
 * (see TenantScopedUserProvider, Customer/TenantUser::canAccessPanel()), and its logo, name,
 * favicon and colours brand the pages (App\Filament\AgencyBranding) — including the login pages.
 *
 * Also builds agency links for emails: any staff- or customer-facing URL must point at the
 * agency's own subdomain — use url() or within() rather than route()/url() directly.
 */
class AgencySubdomain
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * The agency slug in `$host`, or null when the host is the platform itself (the bare domain,
     * a reserved name like www/admin, or an unrelated host).
     */
    public static function slugFromHost(string $host): ?string
    {
        $suffix = '.'.config('agency.domain');

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        if ($slug === '' || str_contains($slug, '.') || in_array($slug, config('agency.reserved_subdomains'), true)) {
            return null;
        }

        return $slug;
    }

    /**
     * The agency's origin, e.g. https://northwind.example.com (keeping APP_URL's scheme and port).
     */
    public static function rootFor(Tenant $tenant): string
    {
        $appUrl = parse_url((string) config('app.url'));
        $scheme = $appUrl['scheme'] ?? 'https';
        $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';

        return "{$scheme}://{$tenant->slug}.".config('agency.domain').$port;
    }

    public static function url(Tenant $tenant, string $path = '/portal'): string
    {
        return static::rootFor($tenant).'/'.ltrim($path, '/');
    }

    /**
     * Runs `$callback` with every generated URL (route(), signed routes, Filament page URLs) rooted
     * at the agency's subdomain — needed for signed links, whose signature covers the host.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function within(Tenant $tenant, Closure $callback): mixed
    {
        URL::useOrigin(static::rootFor($tenant));

        try {
            return $callback();
        } finally {
            // Nothing else in this app forces a URL origin, so clearing it restores the default
            // (the current request's host, or APP_URL outside a request).
            URL::useOrigin(null);
        }
    }
}
