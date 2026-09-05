<?php

namespace App\Filament\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Resources\SubscriptionPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSubscriptionPlan extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = SubscriptionPlanResource::class;
}
