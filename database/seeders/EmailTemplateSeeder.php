<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Mail\EmailTemplates;
use Illuminate\Database\Seeder;

/**
 * Seeds every fixed (always-on) email template the app sends:
 *
 * - `email_template_types` — the catalog of tenant transactional emails
 * - each tenant's editable transactional templates (booking updates, invoices, reminders, portal
 *   password reset, ...) — admin/tenant panel: Communication → Email Templates → Transactional emails
 * - the platform's system emails (admin/staff password resets, SMTP test email) — admin panel:
 *   Communication → Email Templates → System emails
 *
 * The default wording itself is defined in code, so it is never lost with the database:
 * App\Support\TransactionalEmailTypes and App\Support\SystemEmailTypes. To add or change a default
 * email, edit those classes and re-run this seeder:
 *
 *     php artisan db:seed --class=EmailTemplateSeeder
 *
 * Safe to run any number of times: it only creates templates that are missing and never
 * overwrites wording a tenant or Super Admin has already edited (use "Reset to default wording"
 * in the panel for that). Campaign templates are user content and are not seeded.
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(EmailTemplates $templates): void
    {
        $templates->syncTypes();
        $templates->seedSystemTemplates();

        Tenant::query()->each(fn (Tenant $tenant) => $templates->seedTenantTemplates($tenant));
    }
}
