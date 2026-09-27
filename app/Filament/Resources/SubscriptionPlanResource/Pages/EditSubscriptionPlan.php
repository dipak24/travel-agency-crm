<?php

namespace App\Filament\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Resources\SubscriptionPlanResource;
use App\Support\PlanFeatures;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionPlan extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = SubscriptionPlanResource::class;

    /**
     * A feature missing from the stored toggles (a plan saved before they existed, or a feature
     * added later) is allowed — show it switched on rather than off.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['features'] = array_merge(
            array_fill_keys(array_keys(PlanFeatures::ALL), true),
            (array) ($data['features'] ?? []),
        );

        return $data;
    }
}
