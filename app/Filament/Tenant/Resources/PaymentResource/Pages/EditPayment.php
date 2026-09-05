<?php

namespace App\Filament\Tenant\Resources\PaymentResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\PaymentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayment extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
