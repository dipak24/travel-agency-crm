<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksInvoicePayments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use BelongsToTenant, SoftDeletes, TracksInvoicePayments;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'customer_id',
        'invoice_no',
        'amount',
        'tax',
        'discount',
        'total',
        'currency',
        'status',
        'due_date',
        'issued_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if (blank($invoice->invoice_no)) {
                $invoice->invoice_no = $invoice->generateInvoiceNumber();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'tax' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'due_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function generateInvoiceNumber(): string
    {
        $tenant = $this->tenant()->first() ?? Tenant::query()->find($this->tenant_id);
        $tenantSlug = Str::slug($tenant?->slug ?? 'invoice', '');
        $prefix = strtoupper($tenantSlug ?: 'INV');
        $year = now()->year;
        $sequence = static::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf('%s-%s-%04d', $prefix, $year, $sequence);
    }
}
