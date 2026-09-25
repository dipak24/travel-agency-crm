<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingStatusChanged extends Notification
{
    use Queueable, RendersEmailTemplate;

    public function __construct(public Booking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->transactionalMail($this->booking->tenant_id, TransactionalEmailTypes::BOOKING_STATUS_CHANGED, [
            'customer_name' => $notifiable->name,
            'trip_name' => $this->booking->trip_name,
            'booking_status' => $this->booking->status,
        ]);
    }
}
