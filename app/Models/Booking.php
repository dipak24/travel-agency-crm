<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Notifications\BookingStatusChanged;
use App\Services\Mail\TenantMailer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Booking extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::updated(function (self $booking): void {
            if ($booking->wasChanged('status')) {
                if ($customer = $booking->customer) {
                    app(TenantMailer::class)->send($booking->tenant_id, $customer, new BookingStatusChanged($booking));
                }
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'lead_id', 'customer_id', 'package_id', 'fixed_departure_id',
        'trip_name', 'booked_itinerary', 'description', 'start_date', 'end_date',
        'pax_count', 'status', 'total_amount', 'created_by_staff_id', 'customer_notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'pax_count' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function fixedDeparture(): BelongsTo
    {
        return $this->belongsTo(FixedDeparture::class);
    }

    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by_staff_id');
    }

    public function travelers(): HasMany
    {
        return $this->hasMany(BookingTraveler::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Invoice::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BookingDocument::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(BookingAddon::class);
    }

    public function includeExcludes(): HasMany
    {
        return $this->hasMany(BookingIncludeExclude::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('booking')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
