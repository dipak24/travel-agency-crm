<?php

namespace App\Filament\Resources\PlatformEmailTemplateResource\Pages;

use App\Filament\Concerns\HasContainedTabs;
use App\Filament\Resources\PlatformEmailTemplateResource;
use App\Services\Mail\EmailTemplates;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPlatformEmailTemplates extends ListRecords
{
    use HasContainedTabs;

    protected static string $resource = PlatformEmailTemplateResource::class;

    /**
     * Seeds any system email added to the code catalog since this database was set up — idempotent.
     */
    public function mount(): void
    {
        app(EmailTemplates::class)->seedSystemTemplates();

        parent::mount();
    }

    /**
     * Each tab only appears for an admin allowed to manage that kind of template.
     */
    public function getTabs(): array
    {
        return array_filter([
            'campaign' => PlatformEmailTemplateResource::canManageCampaignTemplates()
                ? Tab::make('Campaign templates')->modifyQueryUsing(fn (Builder $query): Builder => $query->marketing())
                : null,
            'system' => PlatformEmailTemplateResource::canManageSystemEmails()
                ? Tab::make('System emails')->modifyQueryUsing(fn (Builder $query): Builder => $query->system())
                : null,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New campaign template')];
    }
}
