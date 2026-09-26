<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Notifications\Concerns\RendersEmailTemplate;
use App\Support\SystemEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A tenant-panel setup invite (new owner/staff with no password yet) or password reset link, sent
 * by a Super Admin or a tenant owner through App\Services\Auth\AccountSetupLinks.
 */
class StaffAccountLink extends Notification
{
    use Queueable, RendersEmailTemplate;

    public function __construct(public string $url, public bool $isInvite) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->systemMail($this->isInvite ? SystemEmailTypes::STAFF_ACCOUNT_SETUP : SystemEmailTypes::STAFF_PASSWORD_RESET, [
            'user_name' => $notifiable->name,
            'reset_url' => $this->url,
            'expire_minutes' => config('auth.passwords.tenant_users.expire'),
            'tenant_name' => Tenant::query()->find($notifiable->tenant_id)?->name ?? config('app.name'),
        ]);
    }
}
