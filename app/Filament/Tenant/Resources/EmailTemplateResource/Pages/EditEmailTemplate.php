<?php

namespace App\Filament\Tenant\Resources\EmailTemplateResource\Pages;

use App\Filament\Tenant\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Services\Mail\EmailTemplates;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

/**
 * @property EmailTemplate $record
 */
class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Whitelisted server-side, not just hidden in the form: a transactional template's name,
        // status and type are fixed, so only its wording can change; category/type never can.
        $editable = $this->record->isTransactional()
            ? ['subject', 'body_html']
            : ['name', 'subject', 'body_html', 'status'];

        return [...Arr::only($data, $editable), 'updated_by' => auth('tenant')->id()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetToDefault')
                ->label('Reset to default wording')
                ->color('gray')
                ->visible(fn (): bool => $this->record->isTransactional())
                ->requiresConfirmation()
                ->modalDescription('Replace this template\'s subject and body with the platform default? Your edits will be lost.')
                ->action(function (): void {
                    app(EmailTemplates::class)->resetToDefault($this->record);
                    $this->fillForm();

                    Notification::make()->success()->title('Template reset to default')->send();
                }),
            DeleteAction::make(),
        ];
    }
}
