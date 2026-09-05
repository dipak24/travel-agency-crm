<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksInvoicePayments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TenantInvoice extends Model
{
    use BelongsToTenant, SoftDeletes, TracksInvoicePayments;

    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'invoice_no',
        'status',
        'currency',
        'subtotal',
        'tax',
        'discount',
        'total',
        'issue_date',
        'due_date',
        'notes',
        'issued_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if (blank($invoice->invoice_no)) {
                $invoice->invoice_no = $invoice->generateInvoiceNumber();
            }

            if (blank($invoice->issue_date)) {
                $invoice->issue_date = now()->toDateString();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'tax' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'issue_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TenantInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TenantPayment::class);
    }

    /**
     * Recompute subtotal/total from the current line items. Called after
     * items are synced from the admin form (see CreateTenantInvoice /
     * EditTenantInvoice), since totals aren't editable form fields.
     */
    public function refreshTotals(): void
    {
        $subtotal = (int) $this->items()->sum('total');

        $this->update([
            'subtotal' => $subtotal,
            'total' => $subtotal + $this->tax - $this->discount,
        ]);
    }

    public function generateInvoiceNumber(): string
    {
        $tenant = $this->tenant()->first() ?? Tenant::query()->find($this->tenant_id);
        $tenantSlug = Str::slug($tenant?->slug ?? 'tenant', '');
        $prefix = strtoupper($tenantSlug ?: 'TENANT');
        $year = now()->year;
        $sequence = static::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf('PB-%s-%s-%04d', $prefix, $year, $sequence);
    }
}
