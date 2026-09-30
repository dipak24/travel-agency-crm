<?php

namespace App\Filament\Tenant\Resources\PackageResource\Concerns;

use App\Filament\Tenant\Resources\PackageResource\PackageFormState;

/**
 * Shared save logic for CreatePackage and EditPackage: the inclusion/exclusion and add-on service
 * lists aren't bound to a relationship, so the ticked rows are synced after the package is saved.
 */
trait SyncsPackageExtras
{
    protected function syncPackageExtras(): void
    {
        PackageFormState::sync(
            $this->record,
            $this->data[PackageFormState::INCLUSIONS] ?? [],
            $this->data[PackageFormState::SERVICES] ?? [],
        );
    }
}
