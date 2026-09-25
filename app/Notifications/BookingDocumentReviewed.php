<?php

namespace App\Notifications;

use App\Models\BookingDocument;
use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingDocumentReviewed extends Notification
{
    use Queueable, RendersEmailTemplate;

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
        $typeKey = $this->document->status === 'rejected'
            ? TransactionalEmailTypes::DOCUMENT_REJECTED
            : TransactionalEmailTypes::DOCUMENT_APPROVED;

        return $this->transactionalMail($this->document->tenant_id, $typeKey, [
            'customer_name' => $notifiable->name,
            'document_type' => $this->document->doc_type,
            'trip_name' => $this->document->booking()->withoutGlobalScopes()->value('trip_name'),
            'rejection_reason' => filled($this->document->rejection_reason) ? $this->document->rejection_reason : 'Not specified',
        ]);
    }
}
