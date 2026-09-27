<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BookingAddon extends Model
{
    use BelongsToTenant, LogsActivity;

    /**
     * Add-ons only count toward the booking total once staff approve them: a customer's portal
     * request doesn't change the price until then.
     */
    public const BILLABLE_STATUSES = ['approved', 'booked'];

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'service_id',
        'unit_price',
        'price',
        'quantity',
        'status',
        'added_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('booking_addon')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
