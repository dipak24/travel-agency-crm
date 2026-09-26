<?php

namespace App\Filament\Tenant\Resources\CustomerResource\Pages;

use App\Filament\Tenant\Resources\CustomerResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ActionGroup::make([
                ...CustomerResource::accountActions(),
                CustomerResource::deleteAction(),
            ])
                ->label('More')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
        ];
    }
}
