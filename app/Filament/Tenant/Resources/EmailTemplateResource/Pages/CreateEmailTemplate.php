<?php

namespace App\Filament\Tenant\Resources\EmailTemplateResource\Pages;

use App\Filament\Tenant\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailTemplate extends CreateRecord
{
    protected static string $resource = EmailTemplateResource::class;

    /**
     * Transactional templates are only ever seeded, so anything created here is a marketing template.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'category' => EmailTemplate::CATEGORY_MARKETING,
            'email_template_type_id' => null,
            'updated_by' => auth('tenant')->id(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index', ['tab' => 'marketing']);
    }
}
