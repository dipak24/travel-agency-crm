<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceEmailed extends Notification
{
    use Queueable, RendersEmailTemplate;

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

        return $this->transactionalMail($this->invoice->tenant_id, TransactionalEmailTypes::INVOICE_EMAILED, [
            'customer_name' => $notifiable->name,
            'invoice_no' => $this->invoice->invoice_no,
            'invoice_total' => $this->formatMoney($this->invoice->total, $this->invoice->currency),
        ])->attachData(
            Pdf::loadView('pdf.invoice', ['invoice' => $this->invoice])->output(),
            "{$this->invoice->invoice_no}.pdf",
            ['mime' => 'application/pdf'],
        );
    }
}
