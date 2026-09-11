<?php

namespace App\Notifications;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceEmailed extends Notification
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->invoice->loadMissing(['tenant', 'customer', 'booking']);
        $amount = number_format($this->invoice->total / 100, 2);

        return (new MailMessage)
            ->subject("Invoice {$this->invoice->invoice_no}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Please find attached invoice {$this->invoice->invoice_no} for {$this->invoice->currency} {$amount}.")
            ->attachData(
                Pdf::loadView('pdf.invoice', ['invoice' => $this->invoice])->output(),
                "{$this->invoice->invoice_no}.pdf",
                ['mime' => 'application/pdf'],
            );
    }
}
