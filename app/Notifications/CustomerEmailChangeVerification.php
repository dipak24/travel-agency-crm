<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the *requested new* address (an on-demand notifiable) — the customer's email only
 * changes once this link is opened (see App\Services\Auth\CustomerEmailChange).
 */
class CustomerEmailChangeVerification extends Notification
{
    use Queueable, RendersEmailTemplate;

    public function __construct(public Customer $customer, public string $url, public int $expireMinutes) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->transactionalMail($this->customer->tenant_id, TransactionalEmailTypes::CUSTOMER_EMAIL_CHANGE_VERIFY, [
            'customer_name' => $this->customer->name,
            'verify_url' => $this->url,
            'expire_minutes' => $this->expireMinutes,
        ]);
    }
}
