<?php

namespace App\Filament\Tenant\Resources\PaymentResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\PaymentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = PaymentResource::class;
}
