<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingAddon extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'service_id',
        'price',
        'quantity',
        'status',
        'added_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'quantity' => 'integer',
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
