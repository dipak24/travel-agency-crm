<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\BookingResource\Pages;
use App\Filament\Tenant\Resources\BookingResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Tenant\Resources\BookingResource\RelationManagers\TravelersRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\IncludeExclude;
use App\Services\FixedDepartureCapacity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationLabel = 'Bookings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 5;

    /**
     * @return array<string, string>
     */
    public static function documentTypes(): array
    {
        return [
            'passport' => 'Passport',
            'pp_photo' => 'PP size photo',
            'visa' => 'Visa',
            'insurance' => 'Insurance',
            'other' => 'Other documents',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Booking details')
                ->schema([
                    Select::make('customer_id')
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm(CustomerResource::quickCreateSchema())
                        ->createOptionAction(fn (Action $action) => $action
                            ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('create', Customer::class))
                            ->modalHeading('Add guest customer')),
                    TextInput::make('trip_name')->required()->maxLength(255),
                    Textarea::make('description')->rows(4),
                    TextInput::make('pax_count')->numeric()->integer()->minValue(1)->required()->default(1),
                    MoneyInput::make('total_amount')->label('Total')->minValue(0)->required()->default(0),
                    Select::make('status')->options([
                        'pending' => 'Pending',
                        'confirmed' => 'Confirmed',
                        'ongoing' => 'Ongoing',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ])->required(),
                    Textarea::make('customer_notes')
                        ->label('Customer\'s note to staff')
                        ->rows(3)
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit')
                        ->helperText('Read-only — written by the customer from their portal.'),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Itinerary')
                ->description('Free-form — a package\'s own itinerary is only a starting point and often diverges from what was actually booked (or there may be no package at all, e.g. a custom private trip).')
                ->schema([
                    RichEditor::make('booked_itinerary')
                        ->label('')
                        ->toolbarButtons([
                            ['bold', 'italic', 'underline', 'strike'],
                            ['h2', 'h3'],
                            ['bulletList', 'orderedList', 'blockquote'],
                            ['undo', 'redo'],
                        ])
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make('Include / exclude list')
                ->schema([
                    CheckboxList::make('include_exclude_selection')
                        ->label('')
                        ->options(fn (): array => IncludeExclude::query()->orderBy('sort_order')->get()
                            ->mapWithKeys(fn (IncludeExclude $item): array => [$item->id => "{$item->title} ({$item->type})"])
                            ->all())
                        ->descriptions(fn (): array => IncludeExclude::query()->orderBy('sort_order')->get()
                            ->mapWithKeys(fn (IncludeExclude $item): array => [$item->id => (string) $item->description])
                            ->all())
                        ->afterStateHydrated(function (CheckboxList $component, ?Model $record): void {
                            $component->state($record?->includeExcludes()->pluck('include_exclude_id')->filter()->all() ?? []);
                        })
                        ->dehydrated(false)
                        ->helperText('Check every catalog item that applies to this booking. Checking an item snapshots its title/description onto the booking — editing the catalog item later won\'t change this booking.')
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make('Upload travel documents')
                ->description('One file (or several) per document type. Accepted: PDF, JPG, PNG. Min 10 KB, max 5 MB per file.')
                ->schema(collect(static::documentTypes())
                    ->map(fn (string $label, string $docType): FileUpload => FileUpload::make("document_files.{$docType}")
                        ->label($label)
                        ->disk('local')
                        ->directory('booking-documents')
                        ->visibility('private')
                        ->multiple()
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'])
                        ->minSize(10)
                        ->maxSize(5120)
                        ->helperText('Max 5 MB, min 10 KB per file.')
                        ->afterStateHydrated(function (FileUpload $component, ?Model $record) use ($docType): void {
                            $component->state($record?->documents()->where('doc_type', $docType)->pluck('file_path')->all() ?? []);
                        })
                        ->dehydrated(false))
                    ->values()
                    ->all())
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Uploaded documents — review')
                ->description('Approve or reject an already-uploaded document. New files are added above, per document type.')
                ->schema([
                    Repeater::make('documents')
                        ->relationship('documents')
                        ->label('')
                        ->schema([
                            Select::make('doc_type')
                                ->label('Type')
                                ->options(static::documentTypes())
                                ->disabled()
                                ->dehydrated(),
                            FileUpload::make('file_path')
                                ->label('File')
                                ->disk('local')
                                ->directory('booking-documents')
                                ->visibility('private')
                                ->disabled()
                                ->dehydrated(),
                            Select::make('status')->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])->default('pending')->required(),
                            TextInput::make('rejection_reason')->label('Rejection reason')->maxLength(255),
                        ])
                        ->itemLabel(fn (array $state): ?string => static::documentTypes()[$state['doc_type'] ?? ''] ?? 'Document')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columns(4)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make('Add-ons')
                ->schema([
                    Repeater::make('addons')
                        ->relationship('addons')
                        ->label('')
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [
                            ...$data,
                            'added_by' => optional(auth('tenant')->user())->email ?? 'staff',
                        ])
                        ->schema([
                            Select::make('service_id')->relationship('service', 'name')->searchable()->preload()->required(),
                            TextInput::make('quantity')->label('Quantity')->numeric()->integer()->minValue(1)->default(1)->required(),
                            MoneyInput::make('price')->label('Price')->minValue(0)->required(),
                            Select::make('status')->options([
                                'requested' => 'Requested',
                                'approved' => 'Approved',
                                'booked' => 'Booked',
                                'cancelled' => 'Cancelled',
                            ])->default('requested')->required(),
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['service_id'] ? 'Add-on' : 'Add-on')
                        ->addActionLabel('Add add-on')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('trip_name')->searchable()->sortable(),
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            TextColumn::make('fixedDeparture.start_date')->label('Departure')->date()->sortable(),
            TextColumn::make('pax_count')->label('Pax'),
            TextColumn::make('total_amount')->label('Total')->money(fn (): string => auth('tenant')->user()->tenant->currency ?? 'USD', divideBy: 100),
            TextColumn::make('status')->badge()->sortable(),
        ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('cancelBooking')
                    ->label('Cancel booking')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This releases any fixed-departure slot the booking was holding. It does not automatically cancel or refund existing invoices/payments.')
                    ->visible(fn (Booking $record): bool => ! in_array($record->status, ['cancelled', 'completed'], true))
                    ->action(function (Booking $record): void {
                        if ($record->fixed_departure_id) {
                            app(FixedDepartureCapacity::class)->release($record->fixedDeparture, $record->pax_count);
                        }

                        $record->update(['status' => 'cancelled']);
                    }),
                ActionGroup::make([
                    EditAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            TravelersRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
