<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Support\TenantContext;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    private ?int $selectedPlanId = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();

        $data['plan_id'] = $tenant->activeSubscription?->plan_id;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->selectedPlanId = $data['plan_id'] ?? null;
        unset($data['plan_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->selectedPlanId === null) {
            return;
        }

        /** @var Tenant $tenant */
        $tenant = $this->getRecord();
        $subscription = $tenant->activeSubscription;

        if ($subscription) {
            if ($subscription->plan_id !== $this->selectedPlanId) {
                $subscription->update(['plan_id' => $this->selectedPlanId]);
            }

            return;
        }

        $tenantContext = app(TenantContext::class);
        $tenantContext->set($tenant);

        try {
            TenantSubscription::query()->create([
                'plan_id' => $this->selectedPlanId,
                'status' => $tenant->status === 'trial' ? 'trialing' : 'active',
                'starts_at' => now(),
            ]);
        } finally {
            $tenantContext->clear();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
