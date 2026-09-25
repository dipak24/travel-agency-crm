<?php

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Spatie\Permission\Models\Permission;

/**
 * Tenant email templates — gated by `manage communications`. Transactional templates (one per
 * platform-defined type) can have their content edited but are never created, deleted, or
 * switched off; new rows made through the panel are always marketing templates. The usage rules
 * themselves live on the model (GuardsEmailTemplateUsage) — these checks just keep the UI honest.
 */
class EmailTemplatePolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    public function view(TenantUser $user, EmailTemplate $template): bool
    {
        return $this->sameTenant($user, $template);
    }

    public function create(TenantUser $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * A marketing template is locked while a scheduled/queued/sending campaign uses it.
     */
    public function update(TenantUser $user, EmailTemplate $template): bool
    {
        return $this->sameTenant($user, $template) && ! $template->isLockedByRunningCampaign();
    }

    /**
     * Never for a transactional template; for a marketing one, only if no campaign references it.
     */
    public function delete(TenantUser $user, EmailTemplate $template): bool
    {
        return $this->sameTenant($user, $template) && $template->isDeletable();
    }

    private function sameTenant(TenantUser $user, EmailTemplate $template): bool
    {
        return $template->tenant_id === $user->tenant_id && $this->canManage($user);
    }

    private function canManage(TenantUser $user): bool
    {
        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', 'manage communications')->where('guard_name', 'tenant')->exists()) {
            return $user->hasRole('Tenant Owner');
        }

        return $user->hasPermissionTo('manage communications');
    }
}
