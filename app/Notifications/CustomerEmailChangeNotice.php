<?php

namespace App\Notifications;

use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Warns the customer's *current* address that a change to a new address was requested.
 */
class CustomerEmailChangeNotice extends Notification
{
    use Queueable, RendersEmailTemplate;

    public function __construct(public string $newEmail) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->transactionalMail($notifiable->tenant_id, TransactionalEmailTypes::CUSTOMER_EMAIL_CHANGE_NOTICE, [
            'customer_name' => $notifiable->name,
            'new_email' => $this->newEmail,
        ]);
    }
}
