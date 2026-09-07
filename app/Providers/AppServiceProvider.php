<?php

namespace App\Providers;

use App\Auth\TenantScopedUserProvider;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\BookingDocument;
use App\Models\BookingTraveler;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\GiftVoucher;
use App\Models\GroupDiscountTier;
use App\Models\IncludeExclude;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lead;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Service;
use App\Models\ServiceAvailability;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Models\TenantUser;
use App\Policies\BookingAddonPolicy;
use App\Policies\BookingDocumentPolicy;
use App\Policies\BookingPolicy;
use App\Policies\BookingTravelerPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InvoiceItemPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\LeadPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\RolePolicy;
use App\Policies\SubscriptionPlanPolicy;
use App\Policies\SuperAdminPolicy;
use App\Policies\TenantCatalogPolicy;
use App\Policies\TenantInvoicePolicy;
use App\Policies\TenantPaymentPolicy;
use App\Policies\TenantPolicy;
use App\Policies\TenantUserPolicy;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Support\CauserResolver;
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
        Gate::policy(InvoiceItem::class, InvoiceItemPolicy::class);
        Gate::policy(BookingTraveler::class, BookingTravelerPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Package::class, TenantCatalogPolicy::class);
        Gate::policy(IncludeExclude::class, TenantCatalogPolicy::class);
        Gate::policy(FixedDeparture::class, TenantCatalogPolicy::class);
        Gate::policy(GroupDiscountTier::class, TenantCatalogPolicy::class);
        Gate::policy(Service::class, TenantCatalogPolicy::class);
        Gate::policy(ServiceAvailability::class, TenantCatalogPolicy::class);
        Gate::policy(PromoCode::class, TenantCatalogPolicy::class);
        Gate::policy(GiftVoucher::class, TenantCatalogPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(SubscriptionPlan::class, SubscriptionPlanPolicy::class);
        Gate::policy(TenantInvoice::class, TenantInvoicePolicy::class);
        Gate::policy(TenantPayment::class, TenantPaymentPolicy::class);

        Auth::provider('tenant_scoped', function ($app, array $config): TenantScopedUserProvider {
            return new TenantScopedUserProvider($app['hash'], $config['model']);
        });

        // The package's default causer resolution always checks the
        // config('auth.defaults.guard') guard ('tenant' here), so an action
        // performed on the super_admin or customer guard would otherwise be
        // logged with no causer at all. Check all three guards instead.
        app(CauserResolver::class)->resolveUsing(
            fn (): ?Model => Auth::guard('super_admin')->user()
                ?? Auth::guard('tenant')->user()
                ?? Auth::guard('customer')->user()
        );
    }
}
