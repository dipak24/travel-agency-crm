<?php

namespace App\Filament\Resources\PlatformEmailTemplateResource\Pages;

use App\Filament\Resources\PlatformEmailTemplateResource;
use App\Models\PlatformEmailTemplate;
use App\Services\Mail\EmailTemplates;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

/**
 * @property PlatformEmailTemplate $record
 */
class EditPlatformEmailTemplate extends EditRecord
{
    protected static string $resource = PlatformEmailTemplateResource::class;

    /**
     * Whitelisted server-side, not just hidden in the form: a system email's name, category and
     * status are fixed, so only its wording can change.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Arr::only($data, $this->record->isSystem()
            ? ['subject', 'body_html']
            : ['name', 'category', 'subject', 'body_html', 'status']);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index', ['tab' => $this->record->isSystem() ? 'system' : 'campaign']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetToDefault')
                ->label('Reset to default wording')
                ->color('gray')
                ->visible(fn (): bool => $this->record->isSystem())
                ->requiresConfirmation()
                ->modalDescription('Replace this email\'s subject and body with the built-in default? Your edits will be lost.')
                ->action(function (): void {
                    app(EmailTemplates::class)->resetSystemToDefault($this->record);
                    $this->fillForm();

                    Notification::make()->success()->title('System email reset to default')->send();
                }),
            DeleteAction::make(),
        ];
    }
}
