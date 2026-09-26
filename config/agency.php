<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer Portal Domain
    |--------------------------------------------------------------------------
    |
    | Each agency's customer portal is served from its own subdomain:
    | https://{agency-slug}.{domain}/portal — the same agency subdomains the
    | public API resolves (APP_PUBLIC_DOMAIN, see ResolvePublicTenant), so one
    | wildcard DNS record (*.domain) and one wildcard TLS certificate cover
    | both. Falls back to APP_URL's host, so locally (APP_URL=
    | http://localhost:8000) an agency is at http://demo-travel.localhost:8000/portal.
    |
    | Keep SESSION_DOMAIN unset: that keeps each agency's login cookie on its
    | own subdomain, so a session can never be shared between agencies.
    |
    */

    'domain' => env('APP_PUBLIC_DOMAIN') ?: (parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | Reserved Subdomains
    |--------------------------------------------------------------------------
    |
    | Never an agency portal: these stay on the platform (admin/staff panels)
    | and can't be taken as an agency slug.
    |
    */

    'reserved_subdomains' => [
        'www', 'admin', 'api', 'app', 'portal', 'tenant', 'tenants', 'mail', 'email', 'smtp', 'ftp',
        'static', 'assets', 'cdn', 'media', 'files', 'support', 'help', 'docs', 'status', 'billing',
        'dashboard', 'login', 'auth', 'account', 'accounts', 'secure', 'pay', 'payments', 'webhooks',
    ],

];
