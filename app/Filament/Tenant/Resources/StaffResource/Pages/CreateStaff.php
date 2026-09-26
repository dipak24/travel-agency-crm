<?php

namespace App\Filament\Tenant\Resources\StaffResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\StaffResource;
use App\Models\TenantUser;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = StaffResource::class;

    /**
     * New staff have no password yet — email them the link to choose their own.
     */
    protected function afterCreate(): void
    {
        /** @var TenantUser $staff */
        $staff = $this->getRecord();

        StaffResource::sendAccessLink($staff);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
