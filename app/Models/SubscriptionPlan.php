<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = ['name', 'price', 'billing_cycle', 'feature_limits', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'feature_limits' => 'array', 'is_active' => 'boolean'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class, 'plan_id');
    }
}
