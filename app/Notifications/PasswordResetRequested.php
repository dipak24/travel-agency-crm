<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\Concerns\RendersEmailTemplate;
use App\Services\Mail\TenantMailer;
use App\Support\SystemEmailTypes;
use App\Support\TransactionalEmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Replaces Filament's hard-coded "Forgot password" email on all three panels (bound in place of
 * Filament\Auth\Notifications\ResetPassword in AppServiceProvider — Filament builds it via the
 * container and then sets `$url`). The wording comes from an editable template:
 *
 * - Super Admin → platform system email, edited under admin Communication → Email Templates → System emails
 * - tenant staff → platform system email, with the staff member's agency name available
 * - customer → the tenant's own `customer_password_reset` transactional template (portal users
 *   know the agency, not the platform)
 *
 * Queued like Filament's original, so a broken SMTP account never errors the "Forgot password"
 * page. Because Filament sends it with ->notify() itself, it can't go through TenantMailer::send();
 * instead it picks the right SMTP mailer per message (TenantMailer::registerMailerFor()), which
 * never switches the app's global default and so is safe in a reused queue worker.
 */
class PasswordResetRequested extends Notification implements ShouldQueue
{
    use Queueable, RendersEmailTemplate;

    /**
     * Set by Filament right after construction (Filament::getResetPasswordUrl()).
     */
    public string $url = '';

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenantId = $notifiable instanceof Customer || $notifiable instanceof TenantUser ? $notifiable->tenant_id : null;

        $mail = match (true) {
            $notifiable instanceof Customer => $this->transactionalMail($tenantId, TransactionalEmailTypes::CUSTOMER_PASSWORD_RESET, [
                'customer_name' => $notifiable->name,
                'reset_url' => $this->url,
                'expire_minutes' => config('auth.passwords.customers.expire'),
            ]),
            $notifiable instanceof TenantUser => $this->systemMail(SystemEmailTypes::STAFF_PASSWORD_RESET, [
                'user_name' => $notifiable->name,
                'reset_url' => $this->url,
                'expire_minutes' => config('auth.passwords.tenant_users.expire'),
                'tenant_name' => Tenant::query()->find($tenantId)?->name ?? config('app.name'),
            ]),
            default => $this->systemMail(SystemEmailTypes::ADMIN_PASSWORD_RESET, [
                'user_name' => $notifiable->name,
                'reset_url' => $this->url,
                'expire_minutes' => config('auth.passwords.super_admins.expire'),
            ]),
        };

        $mailer = app(TenantMailer::class)->registerMailerFor($tenantId);

        if ($mailer !== null) {
            $mail->mailer($mailer['mailer'])->from($mailer['from_address'], $mailer['from_name']);
        }

        return $mail;
    }
}
