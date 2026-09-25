<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Notifications\BookingDocumentReviewed;
use App\Services\Mail\TenantMailer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BookingDocument extends Model
{
    use BelongsToTenant, LogsActivity;

    /**
     * Document types, shared by the tenant booking form and the customer portal so both upload
     * the same set of per-type fields.
     */
    public const TYPES = [
        'passport' => 'Passport',
        'pp_photo' => 'PP size photo',
        'visa' => 'Visa',
        'insurance' => 'Insurance',
        'other' => 'Other documents',
    ];

    /**
     * Upload rules, shared by both panels: accepted MIME types and size limits in kilobytes.
     */
    public const ACCEPTED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];

    public const MIN_SIZE_KB = 10;

    public const MAX_SIZE_KB = 5120;

    public const UPLOAD_RULES_HINT = 'Accepted: PDF, JPG, PNG. Min 10 KB, max 5 MB per file.';

    /**
     * Adds a customer's newly uploaded files to a booking as `pending` documents, one row per file.
     * Unlike syncForBookingDocType() (staff), this only ever adds: a customer can't remove or
     * replace a document staff are reviewing or have already approved. Unknown doc types are
     * ignored. Returns how many documents were added.
     *
     * @param  array<string, array<int, string>|string|null>  $filePathsByType
     */
    public static function addCustomerUploads(Booking $booking, array $filePathsByType, string $uploadedBy): int
    {
        $added = 0;

        foreach (array_intersect_key($filePathsByType, self::TYPES) as $docType => $filePaths) {
            foreach (array_filter((array) $filePaths) as $filePath) {
                $booking->documents()->create([
                    'doc_type' => $docType,
                    'file_path' => $filePath,
                    'status' => 'pending',
                    'uploaded_by' => $uploadedBy,
                ]);
                $added++;
            }
        }

        return $added;
    }

    protected static function booted(): void
    {
        static::updated(function (self $document): void {
            if ($document->wasChanged('status') && in_array($document->status, ['approved', 'rejected'], true)) {
                if ($customer = $document->booking?->customer) {
                    app(TenantMailer::class)->send($document->tenant_id, $customer, new BookingDocumentReviewed($document));
                }
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

    /**
     * Reconciles a booking's documents of one doc type against the file paths currently sitting
     * in that doc type's upload field — used by BookingResource's per-type upload fields instead
     * of the old single repeater with a doc-type dropdown. A file removed from the upload widget
     * removes its row here too; a newly uploaded file becomes a fresh `pending` row.
     *
     * @param  array<int, string>  $filePaths
     */
    public static function syncForBookingDocType(Booking $booking, string $docType, array $filePaths, string $uploadedBy): void
    {
        $filePaths = array_values(array_filter($filePaths));

        $existing = $booking->documents()->where('doc_type', $docType)->get();

        foreach ($existing as $row) {
            if (! in_array($row->file_path, $filePaths, true)) {
                $row->delete();
            }
        }

        foreach (array_diff($filePaths, $existing->pluck('file_path')->all()) as $newPath) {
            $booking->documents()->create([
                'doc_type' => $docType,
                'file_path' => $newPath,
                'status' => 'pending',
                'uploaded_by' => $uploadedBy,
            ]);
        }
    }
}
