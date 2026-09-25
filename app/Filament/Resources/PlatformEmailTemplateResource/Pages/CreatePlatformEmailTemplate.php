<?php

namespace App\Filament\Resources\PlatformEmailTemplateResource\Pages;

use App\Filament\Resources\PlatformEmailTemplateResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

/**
 * Only campaign templates are ever created here — system emails are seeded per key.
 */
class CreatePlatformEmailTemplate extends CreateRecord
{
    protected static string $resource = PlatformEmailTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...Arr::only($data, ['name', 'category', 'subject', 'body_html', 'status']),
            'key' => null,
            'created_by' => auth('super_admin')->id(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index', ['tab' => 'campaign']);
    }
}
