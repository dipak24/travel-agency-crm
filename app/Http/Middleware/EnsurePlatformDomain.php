<?php

namespace App\Http\Middleware;

use App\Support\AgencySubdomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the Super Admin panel on the platform's own domain only. Agency subdomains serve that
 * agency's staff panel and portal, so the platform-wide admin login is never served (or phished)
 * under an agency's address, and stays in one place that can later get IP rules or 2FA.
 */
class EnsurePlatformDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(AgencySubdomain::slugFromHost($request->getHost()) !== null, 404);

        return $next($request);
    }
}
