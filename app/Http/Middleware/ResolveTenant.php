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

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
