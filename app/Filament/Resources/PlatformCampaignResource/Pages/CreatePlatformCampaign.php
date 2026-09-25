<?php

namespace App\Filament\Resources\PlatformCampaignResource\Pages;

use App\Filament\Resources\PlatformCampaignResource;
use App\Models\EmailCampaign;
use Filament\Resources\Pages\CreateRecord;

class CreatePlatformCampaign extends CreateRecord
{
    protected static string $resource = PlatformCampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'owner_type' => EmailCampaign::OWNER_PLATFORM,
            'tenant_id' => null,
            'status' => 'draft',
            'created_by' => auth('super_admin')->id(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
