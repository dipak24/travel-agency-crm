<?php

namespace App\Filament\Tenant\Resources\PackageResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\PackageResource;
use App\Models\Package;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = PackageResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(fn (Package $record, DeleteAction $action) => PackageResource::guardAgainstDeletingPackageWithBookings($record, $action)),
        ];
    }
}
