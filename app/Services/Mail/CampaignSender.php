<?php

namespace App\Services\Mail;

use App\Jobs\SendEmailCampaign;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailTemplate;
use App\Models\EmailUnsubscribe;
use App\Models\PlatformEmailTemplate;
use App\Models\SaasLead;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\CampaignEmail;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sends mass-email campaigns, for both tenants (to their own customers or staff) and the platform
 * (to tenants or saas_leads). Lifecycle: draft → scheduled → queued → sending → sent.
 *
 * Sending runs in App\Jobs\SendEmailCampaign, BATCH_SIZE recipients per job — each job
 * re-dispatches itself while recipients are still pending, so a large audience never has to fit
 * inside one queue-worker timeout. Each recipient row is marked as it goes, so a retried job never
 * emails someone twice. Anyone who unsubscribed from this sender (App\Models\EmailUnsubscribe) is
 * left out when the recipient list is built, and every email carries an unsubscribe link.
 */
class CampaignSender
{
    public const BATCH_SIZE = 100;

    /**
     * Audience types each owner may target.
     */
    public const AUDIENCES = [
        EmailCampaign::OWNER_TENANT => ['customers' => 'All customers', 'staff' => 'All active staff'],
        EmailCampaign::OWNER_PLATFORM => ['tenants' => 'All active tenants', 'saas_leads' => 'SaaS leads'],
    ];

    public function sendNow(EmailCampaign $campaign): void
    {
        $this->ensureReadyToSend($campaign);

        $campaign->update(['status' => 'queued', 'scheduled_at' => null]);

        SendEmailCampaign::dispatch($campaign->id);
    }

    public function schedule(EmailCampaign $campaign, CarbonInterface $sendAt): void
    {
        $this->ensureReadyToSend($campaign);

        if ($sendAt->isPast()) {
            throw new InvalidArgumentException('A campaign can only be scheduled for a future time.');
        }

        $campaign->update(['status' => 'scheduled', 'scheduled_at' => $sendAt]);
    }

    public function cancelSchedule(EmailCampaign $campaign): void
    {
        if ($campaign->status === 'scheduled') {
            $campaign->update(['status' => 'draft', 'scheduled_at' => null]);
        }
    }

    /**
     * Queues every scheduled campaign whose time has come. Returns how many were queued.
     */
    public function dispatchDue(): int
    {
        return EmailCampaign::query()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get()
            ->each(function (EmailCampaign $campaign): void {
                $campaign->update(['status' => 'queued']);
                SendEmailCampaign::dispatch($campaign->id);
            })
            ->count();
    }

    /**
     * Sends the next batch of a queued/sending campaign. Returns true when recipients are still
     * pending, i.e. another batch is needed.
     */
    public function sendBatch(int $campaignId): bool
    {
        EmailCampaign::query()->whereKey($campaignId)->where('status', 'queued')->update(['status' => 'sending']);
        $campaign = EmailCampaign::query()->find($campaignId);

        if ($campaign === null || $campaign->status !== 'sending') {
            return false;
        }

        if ($campaign->tenant_id === null) {
            return $this->sendBatchFor($campaign, null);
        }

        $tenant = Tenant::query()->withTrashed()->find($campaign->tenant_id);

        // A suspended or deleted agency must not keep emailing its customers — including from
        // campaigns it scheduled (or that were mid-send) before it was suspended.
        if ($tenant === null || $tenant->trashed() || $tenant->status === 'suspended') {
            $campaign->update(['status' => 'failed']);

            return false;
        }

        return app(TenantContext::class)->wrap($tenant, fn (): bool => $this->sendBatchFor($campaign, $tenant));
    }

    public function unsubscribeUrl(?int $tenantId, string $email): string
    {
        return URL::signedRoute('email.unsubscribe', [
            'scope' => $tenantId ?? 'platform',
            'email' => Str::lower($email),
        ]);
    }

    private function sendBatchFor(EmailCampaign $campaign, ?Tenant $tenant): bool
    {
        $template = $this->template($campaign);

        if ($template === null) {
            $campaign->update(['status' => 'failed']);

            return false;
        }

        // Built on the first batch — and again if a job died between claiming the campaign and building it.
        if (! $campaign->recipients()->exists()) {
            $this->buildRecipients($campaign);
        }

        $campaign->recipients()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get()
            ->each(fn (EmailCampaignRecipient $recipient) => $this->deliver($campaign, $tenant, $template, $recipient));

        if ($campaign->recipients()->where('status', 'pending')->exists()) {
            return true;
        }

        $campaign->update(['status' => 'sent']);

        return false;
    }

    /**
     * @param  array{subject: string, body: string}  $template
     */
    private function deliver(EmailCampaign $campaign, ?Tenant $tenant, array $template, EmailCampaignRecipient $recipient): void
    {
        $unsubscribeUrl = $this->unsubscribeUrl($campaign->tenant_id, $recipient->email);

        $rendered = app(EmailTemplates::class)->render(
            $campaign->subject_override ?: $template['subject'],
            $template['body'],
            [
                'recipient_name' => $this->recipientName($recipient),
                'recipient_email' => $recipient->email,
                'tenant_name' => $tenant?->name ?? config('app.name'),
                'unsubscribe_url' => $unsubscribeUrl,
            ],
        );

        $delivered = app(TenantMailer::class)->send(
            $campaign->tenant_id,
            Notification::route('mail', $recipient->email),
            new CampaignEmail($rendered, $tenant, $unsubscribeUrl),
        );

        $recipient->update(['status' => $delivered ? 'sent' : 'failed', 'sent_at' => $delivered ? now() : null]);
        $campaign->increment($delivered ? 'sent_count' : 'failed_count');
    }

    /**
     * The campaign's template, only if it's an active marketing template of the campaign's own
     * owner. Scoped explicitly by tenant_id rather than the ambient TenantContext, since this runs
     * from the panel, the scheduler, and the queue alike.
     *
     * @return array{subject: string, body: string}|null
     */
    private function template(EmailCampaign $campaign): ?array
    {
        $template = $campaign->isPlatform()
            ? PlatformEmailTemplate::query()->marketing()->whereKey($campaign->template_id)->where('status', 'active')->first()
            : EmailTemplate::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $campaign->tenant_id)
                ->whereKey($campaign->template_id)
                ->where('category', EmailTemplate::CATEGORY_MARKETING)
                ->where('status', 'active')
                ->first();

        return $template === null ? null : ['subject' => $template->subject, 'body' => $template->body_html];
    }

    private function buildRecipients(EmailCampaign $campaign): void
    {
        $unsubscribed = EmailUnsubscribe::query()
            ->when(
                $campaign->tenant_id === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $campaign->tenant_id),
            )
            ->pluck('email')
            ->map(fn (string $email): string => Str::lower($email))
            ->flip();

        $recipients = $this->audience($campaign)
            ->filter(fn (array $recipient): bool => filled($recipient['email']))
            ->unique(fn (array $recipient): string => Str::lower($recipient['email']))
            ->reject(fn (array $recipient): bool => $unsubscribed->has(Str::lower($recipient['email'])))
            ->values();

        $recipients->each(fn (array $recipient) => $campaign->recipients()->create([...$recipient, 'status' => 'pending']));

        $campaign->update(['total_recipients' => $recipients->count()]);
    }

    /**
     * @return Collection<int, array{recipient_type: string, recipient_id: int, email: ?string}>
     */
    private function audience(EmailCampaign $campaign): Collection
    {
        $filter = $campaign->audience_filter ?? [];

        return match ($campaign->audience_type) {
            'customers' => Customer::query()
                ->when(! empty($filter['customer_types']), fn ($query) => $query->whereIn('type', $filter['customer_types']))
                ->get(['id', 'email'])
                ->map(fn (Customer $customer): array => ['recipient_type' => 'customer', 'recipient_id' => $customer->id, 'email' => $customer->email]),
            'staff' => TenantUser::query()
                ->where('status', 'active')
                ->get(['id', 'email'])
                ->map(fn (TenantUser $user): array => ['recipient_type' => 'staff', 'recipient_id' => $user->id, 'email' => $user->email]),
            'tenants' => Tenant::query()
                ->where('status', '!=', 'suspended')
                ->get()
                ->map(fn (Tenant $tenant): array => [
                    'recipient_type' => 'tenant',
                    'recipient_id' => $tenant->id,
                    'email' => $tenant->billing_email ?? TenantUser::query()->withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)
                        ->orderBy('id')
                        ->value('email'),
                ]),
            'saas_leads' => SaasLead::query()
                ->when(! empty($filter['lead_statuses']), fn ($query) => $query->whereIn('status', $filter['lead_statuses']))
                ->get(['id', 'email'])
                ->map(fn (SaasLead $lead): array => ['recipient_type' => 'saas_lead', 'recipient_id' => $lead->id, 'email' => $lead->email]),
            default => collect(),
        };
    }

    private function recipientName(EmailCampaignRecipient $recipient): string
    {
        $model = match ($recipient->recipient_type) {
            'customer' => Customer::query()->withoutGlobalScopes()->find($recipient->recipient_id),
            'staff' => TenantUser::query()->withoutGlobalScopes()->find($recipient->recipient_id),
            'tenant' => Tenant::query()->find($recipient->recipient_id),
            'saas_lead' => SaasLead::query()->find($recipient->recipient_id),
            default => null,
        };

        return $model?->name ?? 'there';
    }

    /**
     * Checked when a campaign is sent or scheduled, so a problem surfaces to the person clicking
     * the button rather than as a `failed` campaign later. Once scheduled, the template is locked
     * (GuardsEmailTemplateUsage), so it can't become unusable before the send.
     */
    private function ensureReadyToSend(EmailCampaign $campaign): void
    {
        if (! $campaign->isEditable()) {
            throw new InvalidArgumentException('This campaign has already been sent.');
        }

        if ($this->template($campaign) === null) {
            throw new InvalidArgumentException('This campaign\'s template is missing or not active. Activate the template or pick another one first.');
        }
    }
}
