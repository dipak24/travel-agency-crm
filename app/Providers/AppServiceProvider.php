<?php

namespace App\Providers;

use App\Auth\TenantScopedUserProvider;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn (): TenantContext => new TenantContext);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('tenant_scoped', function ($app, array $config): TenantScopedUserProvider {
            return new TenantScopedUserProvider($app['hash'], $config['model']);
        });
    }
}
