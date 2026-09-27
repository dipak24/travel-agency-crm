<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform maintenance mode (admin Platform → System Settings). While it is on, every agency-facing
 * surface — staff panel, customer portal, payment pages and the public API — answers 503 with the
 * Super Admin's message. The admin panel and payment webhooks keep working, so the platform can be
 * switched back on and in-flight payments still settle. Unlike `php artisan down`, it never locks
 * out the Super Admin.
 */
class EnsurePlatformAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = PlatformSetting::query()->where('maintenance_mode', true)->first();

        if ($settings === null) {
            return $next($request);
        }

        $message = $settings->maintenance_message
            ?: 'We are carrying out scheduled maintenance and will be back shortly.';

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '600']);
        }

        return response()->view('platform-maintenance', ['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '600']);
    }
}
