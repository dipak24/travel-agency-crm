<?php

namespace App\Notifications;

use App\Models\GiftVoucher;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GiftVoucherPurchased extends Notification
{
    use Queueable;

    public function __construct(public GiftVoucher $voucher) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format($this->voucher->value / 100, 2);

        return (new MailMessage)
            ->subject('Your gift voucher is ready')
            ->greeting("Hello {$notifiable->name},")
            ->line("Thanks for your purchase! Here is your gift voucher code, worth {$this->voucher->currency} {$amount}:")
            ->line("**{$this->voucher->code}**")
            ->line('Give this code to whoever will use it — it can be redeemed against any invoice with us.');
    }
}
