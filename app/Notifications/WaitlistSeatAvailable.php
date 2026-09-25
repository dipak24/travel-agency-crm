<?php

namespace App\Notifications;

use App\Models\BookingWaitlist;
use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistSeatAvailable extends Notification
{
    use Queueable, RendersEmailTemplate;

    public function __construct(public BookingWaitlist $entry) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $departure = $this->entry->fixedDeparture;

        return $this->transactionalMail($this->entry->tenant_id, TransactionalEmailTypes::WAITLIST_SEAT_AVAILABLE, [
            'customer_name' => $notifiable->name,
            'trip_name' => $departure->package->name,
            'start_date' => $departure->start_date->toFormattedDateString(),
            'end_date' => $departure->end_date->toFormattedDateString(),
            'pax_requested' => $this->entry->pax_requested,
        ]);
    }
}
