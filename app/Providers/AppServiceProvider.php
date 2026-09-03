<?php

namespace App\Providers;

use App\Auth\TenantScopedUserProvider;
use App\Models\Booking;
use App\Models\FixedDeparture;
use App\Models\GroupDiscountTier;
use App\Models\IncludeExclude;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceAvailability;
use App\Models\TenantUser;
use App\Policies\BookingPolicy;
use App\Policies\RolePolicy;
use App\Policies\TenantCatalogPolicy;
use App\Policies\TenantUserPolicy;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(TenantUser::class, TenantUserPolicy::class);
        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(\Spatie\Permission\Models\Role::class, RolePolicy::class);
        Gate::policy(Package::class, TenantCatalogPolicy::class);
        Gate::policy(IncludeExclude::class, TenantCatalogPolicy::class);
        Gate::policy(FixedDeparture::class, TenantCatalogPolicy::class);
        Gate::policy(GroupDiscountTier::class, TenantCatalogPolicy::class);
        Gate::policy(Service::class, TenantCatalogPolicy::class);
        Gate::policy(ServiceAvailability::class, TenantCatalogPolicy::class);

        Auth::provider('tenant_scoped', function ($app, array $config): TenantScopedUserProvider {
            return new TenantScopedUserProvider($app['hash'], $config['model']);
        });
    }
}
