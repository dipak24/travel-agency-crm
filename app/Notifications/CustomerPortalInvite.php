<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerPortalInvite extends Notification
{
    use Queueable;

    public function __construct(public string $url) {}

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
            ->subject('You\'re invited to your travel portal')
            ->greeting("Hello {$notifiable->name},")
            ->line('You can now set up your travel portal account to view your bookings, upload documents, and track your invoices.')
            ->action('Set your password', $this->url)
            ->line('This link will expire in 60 minutes. If you weren\'t expecting this invitation, you can ignore this email.');
    }
}
