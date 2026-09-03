<?php

namespace App\Filament\Tenant\Resources\LeadResource\Pages;

use App\Filament\Tenant\Resources\LeadResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;
}
