<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'status', 'address', 'phone_number', 'mobile_number',
        'billing_email', 'timezone', 'currency', 'logo', 'primary_color', 'secondary_color', 'trial_ends_at',
    ];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function paymentGateways(): HasMany
    {
        return $this->hasMany(TenantPaymentGateway::class);
    }

    public function activeSubscription(): HasOne
    {
        // Tenant-owned models are scoped to the current TenantContext by
        // default (see BelongsToTenant), but the admin panel manages every
        // tenant's subscription with no tenant context set — bypass that
        // scope here since this relation's own tenant_id constraint is
        // already correct regardless of context.
        return $this->hasOne(TenantSubscription::class)->withoutGlobalScopes()->latestOfMany();
    }

    public function tenantInvoices(): HasMany
    {
        // Same cross-tenant admin-panel need as activeSubscription() above.
        return $this->hasMany(TenantInvoice::class)->withoutGlobalScopes();
    }
}
