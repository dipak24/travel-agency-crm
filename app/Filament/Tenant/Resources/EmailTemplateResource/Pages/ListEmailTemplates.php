<?php

namespace App\Filament\Tenant\Resources\EmailTemplateResource\Pages;

use App\Filament\Concerns\HasContainedTabs;
use App\Filament\Tenant\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Services\Mail\EmailTemplates;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEmailTemplates extends ListRecords
{
    use HasContainedTabs;

    protected static string $resource = EmailTemplateResource::class;

    /**
     * Tenants onboarded before a transactional type existed (or before templates existed at all)
     * get their missing editable copies here — seedTenantTemplates() is idempotent.
     */
    public function mount(): void
    {
        app(EmailTemplates::class)->seedTenantTemplates(auth('tenant')->user()->tenant);

        parent::mount();
    }

    public function getTabs(): array
    {
        return [
            'transactional' => Tab::make('Transactional emails')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('category', EmailTemplate::CATEGORY_TRANSACTIONAL)),
            'marketing' => Tab::make('Campaign templates')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('category', EmailTemplate::CATEGORY_MARKETING)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New campaign template')];
    }
}
