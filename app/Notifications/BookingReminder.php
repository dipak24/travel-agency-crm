<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\RendersEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An automated pre-trip reminder (documents / traveler details / balance due) — see
 * App\Services\BookingReminders, which decides when one is due and builds its merge data.
 */
class BookingReminder extends Notification
{
    use Queueable, RendersEmailTemplate;

    /**
     * @param  array<string, scalar|null>  $mergeData
     */
    public function __construct(public Booking $booking, public string $templateKey, public array $mergeData) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->transactionalMail($this->booking->tenant_id, $this->templateKey, $this->mergeData);
    }
}
