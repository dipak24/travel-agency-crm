<?php

namespace App\Notifications;

use App\Models\BookingDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingDocumentReviewed extends Notification
{
    use Queueable;

    public function __construct(public BookingDocument $document) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->greeting("Hello {$notifiable->name},")
            ->subject("Your {$this->document->doc_type} document was {$this->document->status}");

        if ($this->document->status === 'rejected') {
            $mail->line("Your uploaded {$this->document->doc_type} document was rejected.");

            if (filled($this->document->rejection_reason)) {
                $mail->line("Reason: {$this->document->rejection_reason}");
            }

            $mail->line('Please upload a corrected document from your travel portal.');
        } else {
            $mail->line("Your uploaded {$this->document->doc_type} document was approved.");
        }

        return $mail;
    }
}
