<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\BookingResource\Pages;
use App\Filament\Tenant\Resources\BookingResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Tenant\Resources\BookingResource\RelationManagers\TravelersRelationManager;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingTraveler;
use App\Models\Customer;
use App\Models\IncludeExclude;
use App\Services\FixedDepartureCapacity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
        return BookingDocument::TYPES;
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
                    DatePicker::make('start_date')->label('Trip start date'),
                    DatePicker::make('end_date')->label('Trip end date')->afterOrEqual('start_date'),
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
                ->description('One file (or several) per document type. '.BookingDocument::UPLOAD_RULES_HINT)
                ->schema(collect(static::documentTypes())
                    ->map(fn (string $label, string $docType): FileUpload => FileUpload::make("document_files.{$docType}")
                        ->label($label)
                        ->disk('local')
                        ->directory('booking-documents')
                        ->visibility('private')
                        ->multiple()
                        ->acceptedFileTypes(BookingDocument::ACCEPTED_MIME_TYPES)
                        ->minSize(BookingDocument::MIN_SIZE_KB)
                        ->maxSize(BookingDocument::MAX_SIZE_KB)
                        ->helperText('Max 5 MB, min 10 KB per file.')
                        ->afterStateHydrated(function (FileUpload $component, ?Model $record) use ($docType): void {
                            $component->state($record?->documents()->where('doc_type', $docType)->whereNull('booking_traveler_id')->pluck('file_path')->all() ?? []);
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
                            Hidden::make('booking_traveler_id'),
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
                        ->itemLabel(fn (array $state): ?string => (static::documentTypes()[$state['doc_type'] ?? ''] ?? 'Document')
                            .(filled($state['booking_traveler_id'] ?? null)
                                ? ' — '.BookingTraveler::query()->whereKey($state['booking_traveler_id'])->value('name')
                                : ''))
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
            TextColumn::make('trip_name')->searchable()->sortable()->weight('bold'),
            TextColumn::make('customer.name')->label('Customer')
                ->description(fn (Booking $record): string => collect([$record->customer?->email, $record->customer?->phone])->filter()->implode(' · '))
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                    'customer',
                    fn (Builder $customer): Builder => $customer->where(fn (Builder $match): Builder => $match
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('email', "%{$search}%")
                        ->orWhereLike('phone', "%{$search}%")),
                )),
            TextColumn::make('start_date')->label('Trip start')->date('M j, Y')->sortable()->placeholder('—')
                ->state(fn (Booking $record): mixed => $record->start_date ?? $record->fixedDeparture?->start_date),
            TextColumn::make('end_date')->label('Trip end')->date('M j, Y')->sortable()->placeholder('—')
                ->state(fn (Booking $record): mixed => $record->end_date ?? $record->fixedDeparture?->end_date),
            TextColumn::make('pax_count')->label('Pax'),
            TextColumn::make('total_amount')->label('Total')->money(fn (): string => auth('tenant')->user()->tenant->currency ?? 'USD', divideBy: 100),
            TextColumn::make('status')->badge()->sortable(),
        ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'fixedDeparture']))
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Search trip, customer name, email or phone')
            ->filters([
                SelectFilter::make('trip_name')
                    ->label('Trip name')
                    ->options(fn (): array => Booking::query()->orderBy('trip_name')->distinct()->pluck('trip_name', 'trip_name')->all())
                    ->searchable(),
                Filter::make('trip_date')
                    ->label('Trip date')
                    ->schema([
                        DatePicker::make('from')->label('Trip starts from'),
                        DatePicker::make('until')->label('Trip starts until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from): Builder => static::whereTripStart($q, '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until): Builder => static::whereTripStart($q, '<=', $until)))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['from'] ?? null) ? 'Trip starts from '.Carbon::parse($data['from'])->toFormattedDateString() : null,
                        filled($data['until'] ?? null) ? 'Trip starts until '.Carbon::parse($data['until'])->toFormattedDateString() : null,
                    ]))),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('cancelBooking')
                        ->label('Cancel booking')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('This releases any fixed-departure slot the booking was holding. It does not automatically cancel or refund existing invoices/payments.')
                        ->visible(fn (Booking $record): bool => ! in_array($record->status, ['cancelled', 'completed'], true)
                            && (bool) auth('tenant')->user()?->can('update', $record))
                        ->action(function (Booking $record): void {
                            if ($record->fixed_departure_id) {
                                app(FixedDepartureCapacity::class)->release($record->fixedDeparture, $record->pax_count);
                            }

                            $record->update(['status' => 'cancelled']);
                        }),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->size('sm')
                    ->tooltip('Actions'),
            ]);
    }

    /**
     * A booking's trip start is its own start_date, or its fixed departure's when it has none.
     *
     * @param  Builder<Booking>  $query
     * @param  '>='|'<='  $operator
     * @return Builder<Booking>
     */
    private static function whereTripStart(Builder $query, string $operator, string $date): Builder
    {
        return $query->where(fn (Builder $trip): Builder => $trip
            ->whereDate('start_date', $operator, $date)
            ->orWhere(fn (Builder $viaDeparture): Builder => $viaDeparture
                ->whereNull('start_date')
                ->whereHas('fixedDeparture', fn (Builder $departure): Builder => $departure->whereDate('start_date', $operator, $date))));
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
