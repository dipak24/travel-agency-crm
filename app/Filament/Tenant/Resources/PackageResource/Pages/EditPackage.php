<?php

namespace App\Filament\Tenant\Resources\PackageResource\Pages;

use App\Filament\Concerns\HasContainedRelationManagerTabs;
use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\PackageResource;
use App\Filament\Tenant\Resources\PackageResource\Concerns\SyncsPackageExtras;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    use HasContainedRelationManagerTabs, HasFullWidthForm, SyncsPackageExtras;

    protected static string $resource = PackageResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        $this->syncPackageExtras();
    }
}
