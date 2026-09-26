<?php

namespace App\Filament\Portal\Resources\BookingResource\Pages;

use App\Filament\Forms\Components\CountrySelect;
use App\Filament\Portal\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingDocument;
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
                ->visible(fn (): bool => $this->managesTravelers())
                ->form([
                    Repeater::make('travelers')
                        ->label('Travelers')
                        ->schema([
                            TextInput::make('name')->label('Full name')->required()->maxLength(255),
                            TextInput::make('email')->email()->required()->maxLength(255),
                            TextInput::make('phone')->tel()->maxLength(255),
                            DatePicker::make('dob')->label('Date of birth')->native(false)->maxDate(today()),
                            CountrySelect::make('nationality_id', nationality: true)->label('Nationality')->required(),
                            Textarea::make('address')->rows(2),
                        ])
                        ->columns(2)
                        ->addActionLabel('Add another traveler')
                        ->minItems(1)
                        ->defaultItems(1),
                ])
                ->action(function (Booking $record, array $data): void {
                    foreach ($data['travelers'] as $traveler) {
                        $record->travelers()->create([
                            'name' => $traveler['name'],
                            'email' => $traveler['email'],
                            'phone' => $traveler['phone'] ?? null,
                            'dob' => $traveler['dob'] ?? null,
                            'nationality_id' => $traveler['nationality_id'],
                            'address' => $traveler['address'] ?? null,
                            'document_status' => 'pending',
                        ]);
                    }

                    Notification::make()->title('Travelers added')->success()->send();
                }),
            Action::make('uploadTravelerDocuments')
                ->label('Upload traveller documents')
                ->icon('heroicon-o-document-arrow-up')
                ->visible(fn (Booking $record): bool => $this->managesTravelers() && $record->travelers()->exists())
                ->modalDescription('Upload a document for one traveller on this booking. '.BookingDocument::UPLOAD_RULES_HINT)
                ->form([
                    Select::make('booking_traveler_id')
                        ->label('Traveller')
                        ->options(fn (Booking $record): array => $record->travelers()->orderBy('name')->pluck('name', 'id')->all())
                        ->required(),
                    Select::make('doc_type')->label('Document type')->options(BookingDocument::TYPES)->required(),
                    FileUpload::make('files')
                        ->label('Files')
                        ->disk('local')
                        ->directory('booking-documents')
                        ->visibility('private')
                        ->multiple()
                        ->required()
                        ->acceptedFileTypes(BookingDocument::ACCEPTED_MIME_TYPES)
                        ->minSize(BookingDocument::MIN_SIZE_KB)
                        ->maxSize(BookingDocument::MAX_SIZE_KB),
                ])
                ->action(function (Booking $record, array $data): void {
                    $traveler = $record->travelers()->find($data['booking_traveler_id']);

                    if ($traveler === null) {
                        Notification::make()->title('That traveller is not part of this booking.')->danger()->send();

                        return;
                    }

                    $added = BookingDocument::addCustomerUploads(
                        $record,
                        [$data['doc_type'] => $data['files'] ?? []],
                        auth('customer')->user()->email,
                        $traveler,
                    );

                    Notification::make()->title("{$traveler->name}: {$added} file(s) uploaded — pending staff review")->success()->send();
                }),
        ];
    }

    /**
     * Agencies and group leaders book on behalf of other people, so they manage each traveller's
     * details and documents themselves.
     */
    private function managesTravelers(): bool
    {
        return in_array(auth('customer')->user()->type, ['agency', 'group_leader'], true);
    }
}
