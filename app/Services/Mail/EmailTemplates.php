<?php

namespace App\Services\Mail;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateType;
use App\Models\PlatformEmailTemplate;
use App\Models\Tenant;
use App\Support\SystemEmailTypes;
use App\Support\TenantContext;
use App\Support\TransactionalEmailTypes;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves and renders email templates. Transactional emails use the tenant's own template for
 * that type when one exists, falling back to the code-defined default
 * (App\Support\TransactionalEmailTypes) only if the row is missing — so a tenant that never touches
 * its templates still gets working emails. Transactional templates have no on/off switch (they're
 * always `active`, see GuardsEmailTemplateUsage): an email the app must send can't be disabled.
 * Platform system emails (App\Support\SystemEmailTypes) work the same way against the Super
 * Admin's `category = system` platform templates.
 *
 * Merge tag values are always HTML-escaped when substituted into a body: they routinely carry
 * customer-supplied text (names, notes), while the template HTML itself is authored by the
 * tenant's own staff.
 */
class EmailTemplates
{
    /**
     * Upserts every code-defined transactional type into `email_template_types`.
     */
    public function syncTypes(): void
    {
        foreach (TransactionalEmailTypes::all() as $key => $definition) {
            EmailTemplateType::query()->updateOrCreate(['key' => $key], [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'default_subject' => $definition['subject'],
                'default_body_html' => $definition['body'],
                'available_merge_tags' => $definition['merge_tags'],
            ]);
        }
    }

    /**
     * Gives the tenant an editable transactional template for every type it doesn't have yet.
     * Idempotent — safe to call on onboarding and again whenever new types are added.
     */
    public function seedTenantTemplates(Tenant $tenant): void
    {
        $this->syncTypes();

        // tenant_id is set explicitly (and scopes bypassed) because this also runs from
        // DatabaseSeeder, whose WithoutModelEvents skips BelongsToTenant's creating() hook.
        app(TenantContext::class)->wrap($tenant, function () use ($tenant): void {
            $existingTypeIds = EmailTemplate::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('email_template_type_id')
                ->pluck('email_template_type_id');

            EmailTemplateType::query()
                ->whereNotIn('id', $existingTypeIds)
                ->get()
                ->each(fn (EmailTemplateType $type) => EmailTemplate::query()->create([
                    'tenant_id' => $tenant->id,
                    'email_template_type_id' => $type->id,
                    'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                    'name' => $type->name,
                    'subject' => $type->default_subject,
                    'body_html' => $type->default_body_html,
                    'status' => 'active',
                ]));
        });
    }

    /**
     * Restores a transactional template's subject/body to its type's current default.
     */
    public function resetToDefault(EmailTemplate $template): void
    {
        $definition = TransactionalEmailTypes::all()[$template->type?->key] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException('Only transactional templates can be reset to a default.');
        }

        $template->update(['subject' => $definition['subject'], 'body_html' => $definition['body']]);
    }

    /**
     * @param  array<string, scalar|null>  $mergeData
     * @return array{subject: string, html: string}
     */
    public function renderTransactional(?int $tenantId, string $key, array $mergeData): array
    {
        $definition = TransactionalEmailTypes::all()[$key]
            ?? throw new InvalidArgumentException("Unknown transactional email type [{$key}].");

        $template = $tenantId === null ? null : EmailTemplate::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('category', EmailTemplate::CATEGORY_TRANSACTIONAL)
            ->whereHas('type', fn ($query) => $query->where('key', $key))
            ->first();

        return $this->render(
            $template->subject ?? $definition['subject'],
            $template->body_html ?? $definition['body'],
            $mergeData,
        );
    }

    /**
     * Gives the platform an editable `system` template for every system email it doesn't have yet.
     * Idempotent — safe to call from seeding and again whenever new system emails are added.
     */
    public function seedSystemTemplates(): void
    {
        $existingKeys = PlatformEmailTemplate::query()->system()->pluck('key');

        collect(SystemEmailTypes::all())
            ->except($existingKeys->all())
            ->each(fn (array $definition, string $key) => PlatformEmailTemplate::query()->create([
                'key' => $key,
                'category' => SystemEmailTypes::CATEGORY,
                'name' => $definition['name'],
                'subject' => $definition['subject'],
                'body_html' => $definition['body'],
                'status' => 'active',
            ]));
    }

    public function resetSystemToDefault(PlatformEmailTemplate $template): void
    {
        $definition = SystemEmailTypes::all()[$template->key] ?? null;

        if (! $template->isSystem() || $definition === null) {
            throw new InvalidArgumentException('Only system templates can be reset to a default.');
        }

        $template->update(['subject' => $definition['subject'], 'body_html' => $definition['body']]);
    }

    /**
     * @param  array<string, scalar|null>  $mergeData
     * @return array{subject: string, html: string}
     */
    public function renderSystem(string $key, array $mergeData): array
    {
        $definition = SystemEmailTypes::all()[$key]
            ?? throw new InvalidArgumentException("Unknown system email type [{$key}].");

        $template = PlatformEmailTemplate::query()->system()->where('key', $key)->first();

        return $this->render(
            $template->subject ?? $definition['subject'],
            $template->body_html ?? $definition['body'],
            $mergeData,
        );
    }

    /**
     * @param  array<string, scalar|null>  $mergeData
     * @return array{subject: string, html: string}
     */
    public function render(string $subject, string $bodyHtml, array $mergeData): array
    {
        return [
            'subject' => Str::squish($this->substitute($subject, $mergeData, escape: false)),
            'html' => $this->substitute($bodyHtml, $mergeData, escape: true),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $mergeData
     */
    private function substitute(string $text, array $mergeData, bool $escape): string
    {
        // The rich text editor URL-encodes a tag typed into a link's href (`%7B%7B%20action_url%20%7D%7D`).
        $text = preg_replace('/%7B%7B(?:%20|\s)*([a-z0-9_]+)(?:%20|\s)*%7D%7D/i', '{{ $1 }}', $text);

        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function (array $match) use ($mergeData, $escape): string {
            if (! array_key_exists($match[1], $mergeData)) {
                return $match[0];
            }

            $value = (string) ($mergeData[$match[1]] ?? '');

            return $escape ? e($value) : $value;
        }, $text);
    }
}
