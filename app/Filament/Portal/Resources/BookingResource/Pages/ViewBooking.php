<?php

namespace App\Filament\Portal\Resources\BookingResource\Pages;

use App\Filament\Portal\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Service;
use App\Services\BookingAddonRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editNote')
                ->label(fn (Booking $record): string => filled($record->customer_notes) ? 'Edit your note' : 'Add a note to staff')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->form([
                    Textarea::make('customer_notes')
                        ->label('Note to staff')
                        ->rows(4)
                        ->maxLength(2000),
                ])
                ->fillForm(fn (Booking $record): array => ['customer_notes' => $record->customer_notes])
                ->action(function (Booking $record, array $data): void {
                    $record->update(['customer_notes' => $data['customer_notes']]);

                    Notification::make()->title('Note saved')->success()->send();
                }),
            Action::make('uploadDocument')
                ->label('Upload document(s)')
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    Select::make('doc_type')->label('Document type')->options([
                        'passport' => 'Passport',
                        'pp_photo' => 'PP size photo',
                        'visa' => 'Visa',
                        'insurance' => 'Insurance',
                        'other' => 'Other documents',
                    ])->required(),
                    FileUpload::make('files')
                        ->label('File(s)')
                        ->disk('local')
                        ->directory('booking-documents')
                        ->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'])
                        ->multiple()
                        ->required(),
                ])
                ->action(function (Booking $record, array $data): void {
                    $uploadedBy = auth('customer')->user()->email;

                    foreach ((array) $data['files'] as $path) {
                        $record->documents()->create([
                            'doc_type' => $data['doc_type'],
                            'file_path' => $path,
                            'status' => 'pending',
                            'uploaded_by' => $uploadedBy,
                        ]);
                    }

                    Notification::make()->title('Document(s) uploaded — pending staff review')->success()->send();
                }),
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
