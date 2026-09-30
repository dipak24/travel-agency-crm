<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PromoCode extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'discount_type',
        'discount_value',
        'usage_limit',
        'used_count',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The packages this code is restricted to. An empty relation means the
     * code applies globally, to every package (and to invoices with no
     * package at all).
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class);
    }

    /**
     * Why this code can't be used right now for a booking of the given package, or null when it
     * can. A code restricted to packages never applies to a trip without one.
     */
    public function rejectionReason(?int $packageId): ?string
    {
        $now = now();

        return match (true) {
            ! $this->is_active => 'That promo code is not valid.',
            $this->valid_from !== null && $now->lt($this->valid_from) => 'That promo code is not active yet.',
            $this->valid_until !== null && $now->gt($this->valid_until) => 'That promo code has expired.',
            $this->usage_limit !== null && $this->used_count >= $this->usage_limit => 'That promo code has reached its usage limit.',
            ! $this->appliesToPackage($packageId) => "That promo code does not apply to this booking's package.",
            default => null,
        };
    }

    public function appliesToPackage(?int $packageId): bool
    {
        $restrictedPackageIds = $this->packages()->pluck('packages.id');

        return $restrictedPackageIds->isEmpty() || ($packageId !== null && $restrictedPackageIds->contains($packageId));
    }

    /**
     * The discount (minor units) this code takes off the given amount, never more than the amount.
     */
    public function discountOn(int $amount): int
    {
        $discount = $this->discount_type === 'percent'
            ? (int) round($amount * $this->discount_value / 100)
            : $this->discount_value;

        return max(0, min($discount, $amount));
    }
}
