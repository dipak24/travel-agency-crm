<?php

namespace App\Notifications;

use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerPortalInvite extends Notification
{
    use Queueable, RendersEmailTemplate;

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
        return $this->transactionalMail($notifiable->tenant_id, TransactionalEmailTypes::PORTAL_INVITE, [
            'customer_name' => $notifiable->name,
            'action_url' => $this->url,
        ]);
    }
}
