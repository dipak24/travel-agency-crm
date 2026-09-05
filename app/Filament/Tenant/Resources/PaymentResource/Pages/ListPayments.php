<?php

namespace App\Filament\Tenant\Resources\PaymentResource\Pages;

use App\Filament\Tenant\Resources\PaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
