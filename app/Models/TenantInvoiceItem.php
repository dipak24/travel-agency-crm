<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInvoiceItem extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if (blank($item->total)) {
                $item->total = $item->qty * $item->unit_price;
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'tenant_invoice_id',
        'type',
        'description',
        'qty',
        'unit_price',
        'total',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'integer',
            'total' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TenantInvoice::class, 'tenant_invoice_id');
    }
}
