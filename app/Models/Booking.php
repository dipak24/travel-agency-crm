<?php

namespace App\Models;

use App\Enums\BookingType;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToTenant;
use App\Notifications\BookingStatusChanged;
use App\Services\Mail\TenantMailer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Booking extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity, SoftDeletes;

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
        'booking_type', 'duration_days', 'per_person_price', 'base_amount', 'group_discount_tier_id',
        'group_discount_amount', 'inclusions_adjustment', 'addons_amount', 'manual_adjustment',
        'manual_adjustment_reason', 'document_requirements',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'pax_count' => 'integer',
            'total_amount' => 'integer',
            'booking_type' => BookingType::class,
            'duration_days' => 'integer',
            'per_person_price' => 'integer',
            'base_amount' => 'integer',
            'group_discount_amount' => 'integer',
            'inclusions_adjustment' => 'integer',
            'addons_amount' => 'integer',
            'manual_adjustment' => 'integer',
            'document_requirements' => 'array',
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

    public function groupDiscountTier(): BelongsTo
    {
        return $this->belongsTo(GroupDiscountTier::class);
    }

    /**
     * Required/optional flag per document type for this booking: the booking's own copy (which CST
     * can change), else the package's, else the platform defaults.
     *
     * @return array<string, bool>
     */
    public function documentRequirements(): array
    {
        $base = $this->package?->documentRequirements() ?? DocumentType::defaultRequirements();

        return DocumentType::normalizeRequirements(array_merge($base, array_map('boolval', $this->document_requirements ?? [])));
    }

    /**
     * @return array<int, string>
     */
    public function requiredDocumentTypes(): array
    {
        return array_keys(array_filter($this->documentRequirements()));
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
