<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Notifications\BookingDocumentReviewed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BookingDocument extends Model
{
    use BelongsToTenant, LogsActivity;

    protected static function booted(): void
    {
        static::updated(function (self $document): void {
            if ($document->wasChanged('status') && in_array($document->status, ['approved', 'rejected'], true)) {
                $document->booking?->customer?->notify(new BookingDocumentReviewed($document));
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'doc_type',
        'file_path',
        'status',
        'uploaded_by',
        'reviewed_by_staff_id',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('booking_document')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
