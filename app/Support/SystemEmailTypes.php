<?php

namespace App\Support;

/**
 * Platform-level system emails — account emails sent on behalf of the platform itself rather than
 * a tenant. Each entry is seeded as an editable `platform_email_templates` row (`category = system`,
 * `key` = the entry's key) that a Super Admin edits under admin Communication → Email Templates →
 * System emails. System emails are always on — only their wording is editable; the default
 * subject/body here is used only if that row is missing.
 *
 * Customer-facing account emails (e.g. the customer portal password reset) are tenant-branded and
 * live in App\Support\TransactionalEmailTypes instead.
 *
 * Merge tags are written as `{{ tag }}` in both subject and body.
 */
class SystemEmailTypes
{
    public const CATEGORY = 'system';

    public const ADMIN_PASSWORD_RESET = 'admin_password_reset';

    public const STAFF_PASSWORD_RESET = 'staff_password_reset';

    public const MAIL_SETTINGS_TEST = 'mail_settings_test';

    /**
     * @return array<string, array{name: string, description: string, subject: string, body: string, merge_tags: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::ADMIN_PASSWORD_RESET => [
                'name' => 'Password reset — platform admins',
                'description' => 'Sent when a Super Admin uses "Forgot password" on the admin panel login.',
                'subject' => 'Reset your {{ app_name }} admin password',
                'body' => '<p>Hello {{ user_name }},</p><p>We received a request to reset the password for your {{ app_name }} admin account.</p><p><a href="{{ reset_url }}">Reset password</a></p><p>This link will expire in {{ expire_minutes }} minutes. If you did not request a password reset, no further action is required.</p>',
                'merge_tags' => ['user_name', 'reset_url', 'expire_minutes', 'app_name'],
            ],
            self::STAFF_PASSWORD_RESET => [
                'name' => 'Password reset — tenant staff',
                'description' => 'Sent when a travel agency\'s staff member uses "Forgot password" on the tenant panel login.',
                'subject' => 'Reset your {{ tenant_name }} staff password',
                'body' => '<p>Hello {{ user_name }},</p><p>We received a request to reset the password for your {{ tenant_name }} staff account.</p><p><a href="{{ reset_url }}">Reset password</a></p><p>This link will expire in {{ expire_minutes }} minutes. If you did not request a password reset, no further action is required.</p>',
                'merge_tags' => ['user_name', 'reset_url', 'expire_minutes', 'tenant_name', 'app_name'],
            ],
            self::MAIL_SETTINGS_TEST => [
                'name' => 'SMTP test email',
                'description' => 'Sent by "Send test email" on the admin or tenant Email Settings page, to confirm the SMTP settings work.',
                'subject' => 'Test email from {{ sender_name }}',
                'body' => '<p>Hello {{ user_name }},</p><p>This is a test email. If you received it, the outgoing email settings for {{ sender_name }} are working correctly.</p>',
                'merge_tags' => ['user_name', 'sender_name', 'app_name'],
            ],
        ];
    }
}
