<?php

namespace App\Notifications;

use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A staff-triggered portal link for a customer (see App\Services\Auth\AccountSetupLinks): the
 * first-time setup invite for a customer who has never set a password, or a password reset link
 * for one who has. Either way the customer chooses their own password — staff never set it.
 */
class CustomerAccountLink extends Notification
{
    use Queueable, RendersEmailTemplate;

    public function __construct(public string $url, public bool $isInvite) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expireMinutes = config('auth.passwords.customers.expire');

        if ($this->isInvite) {
            return $this->transactionalMail($notifiable->tenant_id, TransactionalEmailTypes::PORTAL_INVITE, [
                'customer_name' => $notifiable->name,
                'action_url' => $this->url,
                'expire_minutes' => $expireMinutes,
            ]);
        }

        return $this->transactionalMail($notifiable->tenant_id, TransactionalEmailTypes::CUSTOMER_PASSWORD_RESET, [
            'customer_name' => $notifiable->name,
            'reset_url' => $this->url,
            'expire_minutes' => $expireMinutes,
        ]);
    }
}
