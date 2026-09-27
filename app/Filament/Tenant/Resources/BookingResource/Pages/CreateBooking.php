<?php

namespace App\Filament\Tenant\Resources\BookingResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\BookingResource;
use App\Filament\Tenant\Resources\BookingResource\Concerns\SyncsBookingExtras;
use Filament\Resources\Pages\CreateRecord;

class CreateBooking extends CreateRecord
{
    use HasFullWidthForm, SyncsBookingExtras;

    protected static string $resource = BookingResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...BookingResource::prepareFormData($data),
            'created_by_staff_id' => auth('tenant')->id(),
        ];
    }

    protected function afterCreate(): void
    {
        $this->syncBookingExtras();
    }
}
