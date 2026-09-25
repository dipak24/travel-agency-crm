<?php

namespace App\Filament\Portal\Resources\BookingResource\Pages;

use App\Filament\Portal\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Service;
use App\Services\BookingAddonRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        // Uploading documents and the note to staff live on their own cards in the infolist
        // (BookingResource::uploadDocumentsAction()/editNoteAction()), next to what they change.
        return [
            Action::make('requestAddon')
                ->label('Request add-on')
                ->icon('heroicon-o-plus-circle')
                ->visible(fn (): bool => Service::query()->where('is_active', true)->exists())
                ->form([
                    Select::make('service_id')
                        ->label('Add-on service')
                        ->options(fn (): array => Service::query()->where('is_active', true)->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('quantity')->numeric()->integer()->minValue(1)->default(1)->required(),
                ])
                ->action(function (Booking $record, array $data): void {
                    $service = Service::query()->where('is_active', true)->find($data['service_id']);

                    if (! $service) {
                        Notification::make()->title('That add-on is no longer available.')->danger()->send();

                        return;
                    }

                    try {
                        app(BookingAddonRequest::class)->request(
                            $record,
                            $service,
                            (int) $data['quantity'],
                            auth('customer')->user()->email,
                        );
                    } catch (LogicException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Add-on requested — pending staff approval')->success()->send();
                }),
            Action::make('addTravelers')
                ->label('Add travelers')
                ->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => in_array(auth('customer')->user()->type, ['agency', 'group_leader'], true))
                ->form([
                    Repeater::make('travelers')
                        ->label('Travelers')
                        ->schema([
                            TextInput::make('name')->required()->maxLength(255),
                            DatePicker::make('dob')->label('Date of birth')->native(false),
                        ])
                        ->addActionLabel('Add another traveler')
                        ->minItems(1)
                        ->defaultItems(1),
                ])
                ->action(function (Booking $record, array $data): void {
                    foreach ($data['travelers'] as $traveler) {
                        $record->travelers()->create([
                            'name' => $traveler['name'],
                            'dob' => $traveler['dob'] ?? null,
                            'document_status' => 'pending',
                        ]);
                    }

                    Notification::make()->title('Travelers added')->success()->send();
                }),
        ];
    }
}
