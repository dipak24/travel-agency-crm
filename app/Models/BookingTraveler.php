<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BookingTraveler extends Model
{
    use BelongsToTenant, LogsActivity;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'name',
        'email',
        'phone',
        'passport_no',
        'dob',
        'nationality_id',
        'address',
        'document_status',
    ];

    protected $hidden = [
        'passport_no',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'passport_no' => 'encrypted',
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

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

    /**
     * Documents uploaded for this traveller specifically (passport, visa, …), as opposed to the
     * booking-level documents that have no traveller.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(BookingDocument::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('booking_traveler')
            ->logFillable()
            ->logExcept(['passport_no'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
