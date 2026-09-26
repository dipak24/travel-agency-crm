<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksInvoicePayments;
use App\Services\GiftVoucherPurchase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Invoice extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes, TracksInvoicePayments;

    public const PURPOSE_GIFT_VOUCHER_PURCHASE = 'gift_voucher_purchase';

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
        'purpose',
        'due_date',
        'issued_by',
        'purchaser_first_name',
        'purchaser_last_name',
        'purchaser_email',
        'purchaser_phone',
    ];

    /**
     * A gift voucher must be bought with real money — promo codes and other gift vouchers can
     * never be applied to its invoice (InvoicePromoRedemption / InvoiceGiftVoucherRedemption).
     */
    public function isGiftVoucherPurchase(): bool
    {
        return $this->purpose === self::PURPOSE_GIFT_VOUCHER_PURCHASE;
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if (blank($invoice->invoice_no)) {
                $invoice->invoice_no = $invoice->generateInvoiceNumber();
            }
        });

        static::updated(function (self $invoice): void {
            if ($invoice->wasChanged('status') && $invoice->status === 'paid' && $invoice->purpose === 'gift_voucher_purchase') {
                app(GiftVoucherPurchase::class)->fulfill($invoice);
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

    public function giftVouchers(): HasMany
    {
        return $this->hasMany(GiftVoucher::class, 'source_invoice_id');
    }

    public function purchaserName(): ?string
    {
        return trim("{$this->purchaser_first_name} {$this->purchaser_last_name}") ?: null;
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('invoice')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
