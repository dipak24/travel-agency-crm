<?php

namespace App\Filament\Tenant\Resources\EmailCampaignResource\Pages;

use App\Filament\Tenant\Resources\EmailCampaignResource;
use App\Models\EmailCampaign;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailCampaign extends CreateRecord
{
    protected static string $resource = EmailCampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth('tenant')->user();

        return [
            ...$data,
            'owner_type' => EmailCampaign::OWNER_TENANT,
            'tenant_id' => $user->tenant_id,
            'status' => 'draft',
            'created_by' => $user->id,
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
