<?php

namespace App\Filament\Tenant\Resources\PackageResource\Pages;

use App\Filament\Concerns\HasContainedTabs;
use App\Filament\Tenant\Resources\PackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPackages extends ListRecords
{
    use HasContainedTabs;

    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Packages are archived instead of deleted; only the Archived tab offers permanent deletion.
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Packages')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', 'archived')),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'archived')),
        ];
    }
}
