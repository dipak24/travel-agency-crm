<?php

namespace App\Filament\Tenant\Resources\ActivityLogResource\Pages;

use App\Filament\Tenant\Resources\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No create button — audit log entries are only ever written by the app itself.
 */
class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;
}
