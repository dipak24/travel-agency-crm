<?php

namespace App\Providers;

use App\Auth\TenantScopedUserProvider;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\BookingDocument;
use App\Models\BookingTraveler;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\GroupDiscountTier;
use App\Models\IncludeExclude;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceAvailability;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Policies\BookingAddonPolicy;
use App\Policies\BookingDocumentPolicy;
use App\Policies\BookingPolicy;
use App\Policies\BookingTravelerPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\LeadPolicy;
use App\Policies\RolePolicy;
use App\Policies\SuperAdminPolicy;
use App\Policies\TenantCatalogPolicy;
use App\Policies\TenantPolicy;
use App\Policies\TenantUserPolicy;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

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
        Gate::policy(SuperAdmin::class, SuperAdminPolicy::class);
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(BookingDocument::class, BookingDocumentPolicy::class);
        Gate::policy(BookingAddon::class, BookingAddonPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(BookingTraveler::class, BookingTravelerPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
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
