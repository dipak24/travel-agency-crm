<?php

namespace App\Support;

/**
 * The code-owned catalog of transactional emails this app sends. Each entry is synced into
 * `email_template_types` and seeded as an editable `email_templates` row per tenant. The default
 * subject/body here is also the fallback whenever a tenant has no active template of its own.
 *
 * Merge tags are written as `{{ tag }}` in both subject and body.
 */
class TransactionalEmailTypes
{
    public const PORTAL_INVITE = 'portal_invite';

    public const BOOKING_STATUS_CHANGED = 'booking_status_changed';

    public const DOCUMENT_APPROVED = 'document_approved';

    public const DOCUMENT_REJECTED = 'document_rejected';

    public const INVOICE_EMAILED = 'invoice_emailed';

    public const INVOICE_PAYMENT_LINK = 'invoice_payment_link';

    public const GIFT_VOUCHER_PURCHASED = 'gift_voucher_purchased';

    public const REMINDER_DOCUMENTS = 'reminder_documents';

    public const REMINDER_TRAVELER_INFO = 'reminder_traveler_info';

    public const REMINDER_BALANCE_DUE = 'reminder_balance_due';

    public const WAITLIST_SEAT_AVAILABLE = 'waitlist_seat_available';

    public const CUSTOMER_PASSWORD_RESET = 'customer_password_reset';

    /**
     * @return array<string, array{name: string, description: string, subject: string, body: string, merge_tags: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::PORTAL_INVITE => [
                'name' => 'Customer portal invitation',
                'description' => 'Sent when staff invite a customer to set up their travel portal account.',
                'subject' => 'You\'re invited to your {{ tenant_name }} travel portal',
                'body' => '<p>Hello {{ customer_name }},</p><p>You can now set up your travel portal account to view your bookings, upload documents, and track your invoices.</p><p><a href="{{ action_url }}">Set your password</a></p><p>This link will expire in 60 minutes. If you weren\'t expecting this invitation, you can ignore this email.</p>',
                'merge_tags' => ['customer_name', 'tenant_name', 'action_url'],
            ],
            self::BOOKING_STATUS_CHANGED => [
                'name' => 'Booking status changed',
                'description' => 'Sent to the customer whenever a booking\'s status changes.',
                'subject' => 'Your booking "{{ trip_name }}" is now {{ booking_status }}',
                'body' => '<p>Hello {{ customer_name }},</p><p>The status of your booking "{{ trip_name }}" has changed to: <strong>{{ booking_status }}</strong>.</p><p>Log in to your travel portal to see the full booking details.</p>',
                'merge_tags' => ['customer_name', 'trip_name', 'booking_status', 'tenant_name'],
            ],
            self::DOCUMENT_APPROVED => [
                'name' => 'Document approved',
                'description' => 'Sent when staff approve a travel document the customer uploaded.',
                'subject' => 'Your {{ document_type }} document was approved',
                'body' => '<p>Hello {{ customer_name }},</p><p>Your uploaded {{ document_type }} document for "{{ trip_name }}" was approved.</p>',
                'merge_tags' => ['customer_name', 'document_type', 'trip_name', 'tenant_name'],
            ],
            self::DOCUMENT_REJECTED => [
                'name' => 'Document rejected',
                'description' => 'Sent when staff reject a travel document the customer uploaded.',
                'subject' => 'Your {{ document_type }} document was rejected',
                'body' => '<p>Hello {{ customer_name }},</p><p>Your uploaded {{ document_type }} document for "{{ trip_name }}" was rejected.</p><p>Reason: {{ rejection_reason }}</p><p>Please upload a corrected document from your travel portal.</p>',
                'merge_tags' => ['customer_name', 'document_type', 'trip_name', 'rejection_reason', 'tenant_name'],
            ],
            self::INVOICE_EMAILED => [
                'name' => 'Invoice',
                'description' => 'Sent with the invoice PDF attached when staff email an invoice.',
                'subject' => 'Invoice {{ invoice_no }}',
                'body' => '<p>Hello {{ customer_name }},</p><p>Please find attached invoice {{ invoice_no }} for {{ invoice_total }}.</p>',
                'merge_tags' => ['customer_name', 'invoice_no', 'invoice_total', 'tenant_name'],
            ],
            self::INVOICE_PAYMENT_LINK => [
                'name' => 'Payment link',
                'description' => 'Sent when staff send a no-login payment link for an invoice.',
                'subject' => 'Payment requested — invoice {{ invoice_no }}',
                'body' => '<p>Hello {{ customer_name }},</p><p>You have an outstanding balance of {{ balance_due }} on invoice {{ invoice_no }}.</p><p><a href="{{ action_url }}">Pay invoice</a></p><p>No account or login is required — this link is unique to you and will expire in 14 days.</p>',
                'merge_tags' => ['customer_name', 'invoice_no', 'balance_due', 'action_url', 'tenant_name'],
            ],
            self::GIFT_VOUCHER_PURCHASED => [
                'name' => 'Gift voucher purchased',
                'description' => 'Sent with the voucher code once a gift voucher purchase is paid.',
                'subject' => 'Your gift voucher is ready',
                'body' => '<p>Hello {{ recipient_name }},</p><p>Thanks for your purchase! Here is your gift voucher code, worth {{ voucher_value }}:</p><p><strong>{{ voucher_code }}</strong></p><p>Give this code to whoever will use it — it can be redeemed against any invoice with us.</p>',
                'merge_tags' => ['recipient_name', 'voucher_code', 'voucher_value', 'tenant_name'],
            ],
            self::REMINDER_DOCUMENTS => [
                'name' => 'Reminder: travel documents',
                'description' => 'Automated reminder when a booking still needs travel documents.',
                'subject' => 'Reminder: documents needed for "{{ trip_name }}"',
                'body' => '<p>Hello {{ customer_name }},</p><p>Your trip "{{ trip_name }}" starts on {{ start_date }} ({{ days_until }} days away), and we still need your travel documents.</p><p><a href="{{ portal_url }}">Upload them in your travel portal</a></p>',
                'merge_tags' => ['customer_name', 'trip_name', 'start_date', 'days_until', 'portal_url', 'tenant_name'],
            ],
            self::REMINDER_TRAVELER_INFO => [
                'name' => 'Reminder: traveler details',
                'description' => 'Automated reminder when a booking is missing traveler names, dates of birth, or passport numbers.',
                'subject' => 'Reminder: traveler details needed for "{{ trip_name }}"',
                'body' => '<p>Hello {{ customer_name }},</p><p>Your trip "{{ trip_name }}" starts on {{ start_date }} ({{ days_until }} days away), and some traveler details are still missing.</p><p>Please reply to this email or contact us so we can complete your booking.</p>',
                'merge_tags' => ['customer_name', 'trip_name', 'start_date', 'days_until', 'portal_url', 'tenant_name'],
            ],
            self::REMINDER_BALANCE_DUE => [
                'name' => 'Reminder: balance due',
                'description' => 'Automated payment reminder when a booking has an unpaid invoice balance.',
                'subject' => 'Payment reminder for "{{ trip_name }}"',
                'body' => '<p>Hello {{ customer_name }},</p><p>Your trip "{{ trip_name }}" starts on {{ start_date }} ({{ days_until }} days away). You have an outstanding balance of {{ balance_due }}.</p><p><a href="{{ portal_url }}">Pay from your travel portal</a></p>',
                'merge_tags' => ['customer_name', 'trip_name', 'start_date', 'days_until', 'balance_due', 'portal_url', 'tenant_name'],
            ],
            self::WAITLIST_SEAT_AVAILABLE => [
                'name' => 'Waitlist: seats available',
                'description' => 'Sent when staff notify a waitlisted customer that seats have opened up.',
                'subject' => 'Seats are available for {{ trip_name }}',
                'body' => '<p>Hello {{ customer_name }},</p><p>Good news — seats have opened up on {{ trip_name }} ({{ start_date }} to {{ end_date }}), which you were waitlisted for ({{ pax_requested }} traveler(s)).</p><p>Reply to this email or contact us soon to secure your place.</p>',
                'merge_tags' => ['customer_name', 'trip_name', 'start_date', 'end_date', 'pax_requested', 'tenant_name'],
            ],
            self::CUSTOMER_PASSWORD_RESET => [
                'name' => 'Portal password reset',
                'description' => 'Sent when a customer uses "Forgot password" on the travel portal login.',
                'subject' => 'Reset your {{ tenant_name }} travel portal password',
                'body' => '<p>Hello {{ customer_name }},</p><p>We received a request to reset the password for your {{ tenant_name }} travel portal account.</p><p><a href="{{ reset_url }}">Reset password</a></p><p>This link will expire in {{ expire_minutes }} minutes. If you did not request a password reset, no further action is required.</p>',
                'merge_tags' => ['customer_name', 'reset_url', 'expire_minutes', 'tenant_name'],
            ],
        ];
    }
}
