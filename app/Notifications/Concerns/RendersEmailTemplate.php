<?php

namespace App\Notifications\Concerns;

use App\Models\Tenant;
use App\Services\Mail\EmailTemplates;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Builds a notification's MailMessage from an editable email template (see EmailTemplates),
 * wrapped in the shared tenant-branded layout (resources/views/emails/templated.blade.php).
 */
trait RendersEmailTemplate
{
    /**
     * @param  array<string, scalar|null>  $mergeData
     */
    protected function transactionalMail(?int $tenantId, string $typeKey, array $mergeData): MailMessage
    {
        $tenant = $tenantId === null ? null : Tenant::query()->find($tenantId);

        $rendered = app(EmailTemplates::class)->renderTransactional($tenantId, $typeKey, [
            'tenant_name' => $tenant?->name ?? config('app.name'),
            ...$mergeData,
        ]);

        return $this->renderedMail($rendered, $tenant);
    }

    /**
     * A platform system email (App\Support\SystemEmailTypes), rendered from the Super Admin's
     * editable system template and sent under the platform's own name.
     *
     * @param  array<string, scalar|null>  $mergeData
     */
    protected function systemMail(string $typeKey, array $mergeData): MailMessage
    {
        $rendered = app(EmailTemplates::class)->renderSystem($typeKey, [
            'app_name' => config('app.name'),
            ...$mergeData,
        ]);

        return $this->renderedMail($rendered, null);
    }

    /**
     * @param  array{subject: string, html: string}  $rendered
     */
    protected function renderedMail(array $rendered, ?Tenant $tenant, ?string $unsubscribeUrl = null): MailMessage
    {
        return (new MailMessage)
            ->subject($rendered['subject'])
            ->view('emails.templated', [
                'subject' => $rendered['subject'],
                'html' => $rendered['html'],
                'senderName' => $tenant?->name ?? config('app.name'),
                'brandColor' => $tenant?->primary_color ?: '#2563eb',
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    protected function formatMoney(int $minorUnits, ?string $currency): string
    {
        return Money::format($minorUnits, $currency ?? 'USD');
    }
}
