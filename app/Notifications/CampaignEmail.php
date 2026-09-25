<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Notifications\Concerns\RendersEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * One recipient's copy of a marketing campaign, already rendered by CampaignSender. Carries
 * RFC 8058 one-click `List-Unsubscribe` headers alongside the visible footer link.
 */
class CampaignEmail extends Notification
{
    use Queueable, RendersEmailTemplate;

    /**
     * @param  array{subject: string, html: string}  $rendered
     */
    public function __construct(public array $rendered, public ?Tenant $tenant, public string $unsubscribeUrl) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->renderedMail($this->rendered, $this->tenant, $this->unsubscribeUrl)
            ->withSymfonyMessage(function (Email $message): void {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$this->unsubscribeUrl}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
