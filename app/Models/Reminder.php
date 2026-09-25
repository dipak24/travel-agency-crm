<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A log of one automated reminder email (see App\Services\BookingReminders). The row doubles as
 * the de-duplication key: one per booking + requirement type + schedule rule.
 */
class Reminder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'booking_id', 'requirement_type', 'recipient_type', 'channel', 'reminder_rule', 'sent_at', 'status',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
