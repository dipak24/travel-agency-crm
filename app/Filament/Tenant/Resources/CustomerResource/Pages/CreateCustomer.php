<?php

namespace App\Filament\Tenant\Resources\CustomerResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\CustomerResource;
use App\Models\Customer;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = CustomerResource::class;

    /**
     * A staff-created customer starts Pending with no password; email them the setup link that
     * verifies their address and lets them choose their own password.
     */
    protected function afterCreate(): void
    {
        /** @var Customer $customer */
        $customer = $this->getRecord();

        CustomerResource::sendAccessLink($customer);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
