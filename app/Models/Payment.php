<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'amount',
        'currency',
        'method',
        'type',
        'status',
        'transaction_ref',
        'paid_at',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $payment): void {
            $payment->invoice()->withoutGlobalScopes()->first()?->recalculateStatus();
        });

        static::deleted(function (self $payment): void {
            $payment->invoice()->withoutGlobalScopes()->first()?->recalculateStatus();
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
