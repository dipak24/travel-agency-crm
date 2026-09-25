<?php

namespace App\Notifications;

use App\Models\GiftVoucher;
use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GiftVoucherPurchased extends Notification
{
    use Queueable, RendersEmailTemplate;

    public function __construct(public GiftVoucher $voucher, public string $recipientName) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->transactionalMail($this->voucher->tenant_id, TransactionalEmailTypes::GIFT_VOUCHER_PURCHASED, [
            'recipient_name' => $this->recipientName,
            'voucher_code' => $this->voucher->code,
            'voucher_value' => $this->formatMoney($this->voucher->value, $this->voucher->currency),
        ]);
    }
}
