<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoicePaymentLink extends Notification
{
    use Queueable, RendersEmailTemplate;

    public function __construct(public Invoice $invoice, public string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->transactionalMail($this->invoice->tenant_id, TransactionalEmailTypes::INVOICE_PAYMENT_LINK, [
            'customer_name' => $notifiable->name,
            'invoice_no' => $this->invoice->invoice_no,
            'balance_due' => $this->formatMoney($this->invoice->balanceDue(), $this->invoice->currency),
            'action_url' => $this->url,
        ]);
    }
}
