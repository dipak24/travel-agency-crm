<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftVoucher extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'value',
        'currency',
        'issued_to',
        'source_invoice_id',
        'source_invoice_item_id',
        'recipient_first_name',
        'recipient_last_name',
        'recipient_email',
        'recipient_phone',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function issuedTo(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'issued_to');
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    public function sourceInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'source_invoice_item_id');
    }

    public function recipientName(): ?string
    {
        return trim("{$this->recipient_first_name} {$this->recipient_last_name}") ?: null;
    }
}
