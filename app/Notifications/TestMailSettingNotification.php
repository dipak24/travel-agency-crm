<?php

namespace App\Notifications;

use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\SystemEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Send test email" on the admin and tenant Email Settings pages. Its wording is the platform
 * system email `mail_settings_test`; delivery goes through TenantMailer, so it proves the SMTP
 * account being tested.
 */
class TestMailSettingNotification extends Notification
{
    use Queueable, RendersEmailTemplate;

    /**
     * @param  string|null  $senderName  Whose settings are being tested — the tenant's name, or null for the platform's.
     */
    public function __construct(public ?string $senderName = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->systemMail(SystemEmailTypes::MAIL_SETTINGS_TEST, [
            'user_name' => $notifiable->name ?? 'there',
            'sender_name' => $this->senderName ?? config('app.name'),
        ]);
    }
}
