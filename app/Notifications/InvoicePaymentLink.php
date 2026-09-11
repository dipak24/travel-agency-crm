<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoicePaymentLink extends Notification
{
    use Queueable;

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
        $amount = number_format($this->invoice->balanceDue() / 100, 2);

        return (new MailMessage)
            ->subject("Payment requested — invoice {$this->invoice->invoice_no}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have an outstanding balance of {$this->invoice->currency} {$amount} on invoice {$this->invoice->invoice_no}.")
            ->action('Pay invoice', $this->url)
            ->line('No account or login is required — this link is unique to you and will expire in 14 days.');
    }
}
