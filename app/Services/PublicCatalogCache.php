<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Caches public API catalog responses per tenant. Invalidation works by rotating a per-tenant
 * version token (baked into every key) rather than cache tags, so it behaves the same on every
 * cache store — Redis in production, the `database` store in dev, `array` in tests.
 */
class PublicCatalogCache
{
    public const TTL_SECONDS = 300;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(int $tenantId, string $key, Closure $callback): mixed
    {
        return Cache::remember(
            "public-api:{$tenantId}:{$this->version($tenantId)}:".sha1($key),
            self::TTL_SECONDS,
            $callback,
        );
    }

    public function flush(int $tenantId): void
    {
        Cache::forever($this->versionKey($tenantId), Str::random(12));
    }

    private function version(int $tenantId): string
    {
        return Cache::rememberForever($this->versionKey($tenantId), fn (): string => Str::random(12));
    }

    private function versionKey(int $tenantId): string
    {
        return "public-api:{$tenantId}:version";
    }
}
