<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingStatusChanged extends Notification
{
    use Queueable;

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
        return (new MailMessage)
            ->subject("Your booking \"{$this->booking->trip_name}\" is now {$this->booking->status}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The status of your booking \"{$this->booking->trip_name}\" has changed to: {$this->booking->status}.")
            ->line('Log in to your travel portal to see the full booking details.');
    }
}
