---
paths:
  - 'app/Notifications/**'
---

# Notifications

## Customer-facing emails render from editable templates — add a TransactionalEmailTypes entry, not MailMessage lines
Every transactional notification builds its MailMessage via App\Notifications\Concerns\RendersEmailTemplate::transactionalMail($tenantId, TransactionalEmailTypes::X, $mergeData). That uses the tenant's own `active` EmailTemplate for that type if one exists, otherwise the code default. To add a new email: add a key and default subject/body/merge_tags to App\Support\TransactionalEmailTypes (tenants get an editable copy automatically via EmailTemplates::seedTenantTemplates), then call transactionalMail(). Don't hand-write ->line()/->action() — tenants could never edit that wording. Merge values are HTML-escaped by the renderer; pass raw values, not HTML. Marketing mail (CampaignEmail) uses renderedMail() with an unsubscribe URL; transactional mail never carries one.

## Vendor-sent emails (Filament password reset) are swapped via the container and pick their SMTP per message
Filament builds its "Forgot password" email with app(Filament\Auth\Notifications\ResetPassword::class, ['token' => ...]) and sends it itself with ->notify(). AppServiceProvider binds that class to App\Notifications\PasswordResetRequested (templated: Super Admin/staff → platform system templates in App\Support\SystemEmailTypes, edited at admin Communication → Email Templates → System emails; customers → the tenant's customer_password_reset template). Since it can't go through TenantMailer::send(), it calls TenantMailer::registerMailerFor($tenantId) and sets ->mailer()/->from() on its own MailMessage. That never switches the global mail.default, so it's the one notification that may safely implement ShouldQueue. Use the same pattern for any other vendor-sent email. Don't use TenantMailer's config swap inside a queued notification.
