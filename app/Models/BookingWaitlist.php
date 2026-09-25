<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingWaitlist extends Model
{
    use BelongsToTenant;

    /**
     * The migrated table is singular (`booking_waitlist`), not Eloquent's default `booking_waitlists`.
     */
    protected $table = 'booking_waitlist';

    protected $fillable = [
        'tenant_id', 'fixed_departure_id', 'customer_id', 'lead_id', 'pax_requested',
        'position', 'status', 'notified_at', 'notified_by_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'pax_requested' => 'integer',
            'position' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fixedDeparture(): BelongsTo
    {
        return $this->belongsTo(FixedDeparture::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function notifiedByStaff(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'notified_by_staff_id');
    }
}
