<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('tenant')->user() ?? auth('customer')->user();

        if ($user instanceof TenantUser || $user instanceof Customer) {
            $this->tenantContext->set($user->tenant);
        }

        return $next($request);
    }

    /**
     * Clearing the tenant context here (rather than in a `finally` around `$next()` in
     * `handle()`) matters because Livewire replays persistent middleware like this one
     * against an isolated pipeline that terminates immediately (see
     * `Livewire\Drawer\Utils::applyMiddleware()`), before the actual component method
     * runs. A `finally` block would clear the context right after setting it, on every
     * Livewire update request. `terminate()` only runs once, after the real request
     * has fully completed.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }
}
