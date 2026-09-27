<?php

namespace App\Filament\Tenant\Resources;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\DocumentType;
use App\Enums\PricingUnit;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\BookingResource\BookingFormState;
use App\Filament\Tenant\Resources\BookingResource\Pages;
use App\Filament\Tenant\Resources\BookingResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Tenant\Resources\BookingResource\RelationManagers\TravelersRelationManager;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingIncludeExclude;
use App\Models\BookingTraveler;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\IncludeExclude;
use App\Models\Package;
use App\Models\Service;
use App\Services\BookingPricing;
use App\Services\BookingSchedule;
use App\Services\FixedDepartureCapacity;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
            static::tripSection(),
            Section::make('Itinerary')
                ->description('Copied from the package when you pick one. Change it freely for this booking — the package itself is not affected.')
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
                ->collapsible()
                ->columnSpanFull(),
            static::lineItemsSection(BookingFormState::INCLUSIONS),
            static::lineItemsSection(BookingFormState::EXCLUSIONS),
            static::addonsSection(),
            static::documentRequirementsSection(),
            Section::make('Upload travel documents')
                ->description('Upload per document type. '.BookingDocument::UPLOAD_RULES_HINT.' "Other documents" takes up to '.BookingDocument::MAX_OTHER_FILES.' files.')
                ->schema(collect(static::documentTypes())
                    ->map(fn (string $label, string $docType): FileUpload => FileUpload::make("document_files.{$docType}")
                        ->label($docType === DocumentType::Other->value ? "{$label} (optional)" : $label)
                        ->disk('local')
                        ->directory('booking-documents')
                        ->visibility('private')
                        ->multiple()
                        ->maxFiles($docType === DocumentType::Other->value ? BookingDocument::MAX_OTHER_FILES : null)
                        ->acceptedFileTypes(BookingDocument::ACCEPTED_MIME_TYPES)
                        ->minSize(BookingDocument::MIN_SIZE_KB)
                        ->maxSize(BookingDocument::MAX_SIZE_KB)
                        ->helperText($docType === DocumentType::Other->value
                            ? BookingDocument::OTHER_UPLOAD_HINT
                            : 'Select one or several files. PDF, JPG, PNG — 10 KB to 5 MB each.')
                        ->afterStateHydrated(function (FileUpload $component, ?Model $record) use ($docType): void {
                            $component->state($record?->documents()->where('doc_type', $docType)->whereNull('booking_traveler_id')->pluck('file_path')->all() ?? []);
                        })
                        ->dehydrated(false))
                    ->values()
                    ->all())
                ->columns(2)
                ->collapsible()
                ->columnSpanFull(),
            Section::make('Uploaded documents — review')
                ->description('Approve, reject or download an uploaded document. New files are added above, per document type.')
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
                        ->extraItemActions([
                            Action::make('download')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->tooltip('Download')
                                ->action(function (array $arguments, Repeater $component): ?StreamedResponse {
                                    $filePath = $component->getRawItemState($arguments['item'])['file_path'] ?? null;
                                    $filePath = is_array($filePath) ? reset($filePath) : $filePath;

                                    return static::downloadDocument($component->getRecord(), $filePath);
                                }),
                        ])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columns(4)
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->columnSpanFull(),
            static::priceSummarySection(),
        ]);
    }

    /**
     * Booking type, customer, package/departure, dates and pax. Picking a package fills the trip
     * name, duration, price, itinerary, inclusion/exclusion lists and document requirements;
     * picking a departure fills its dates and price.
     */
    private static function tripSection(): Section
    {
        $isFixedGroup = fn (Get $get): bool => BookingFormState::bookingType($get('booking_type')) === BookingType::FixedGroup;

        return Section::make('Trip')
            ->schema([
                ToggleButtons::make('booking_type')
                    ->label('Booking type')
                    ->options(BookingType::class)
                    ->default(BookingType::Individual->value)
                    ->inline()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        if (BookingFormState::bookingType($get('booking_type')) !== BookingType::FixedGroup) {
                            $set('fixed_departure_id', null);
                        }
                    })
                    ->columnSpanFull(),
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm(CustomerResource::quickCreateSchema())
                    ->createOptionAction(fn (Action $action) => $action
                        ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('create', Customer::class))
                        ->modalHeading('Add guest customer')),
                Select::make('fixed_departure_id')
                    ->label('Fixed departure')
                    ->options(fn (?Booking $record): array => static::departureOptions($record))
                    ->searchable()
                    ->visible($isFixedGroup)
                    ->required($isFixedGroup)
                    ->helperText('The departure sets the package, price, days and dates.')
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, $state) => static::fillFromDeparture($get, $set, $state)),
                TextEntry::make('departure_package')
                    ->label('Package')
                    ->state(fn (Get $get): ?string => filled($get('package_id')) ? Package::query()->whereKey($get('package_id'))->value('name') : null)
                    ->placeholder('Choose a departure')
                    ->visible($isFixedGroup),
                Select::make('package_id')
                    ->label('Package')
                    ->options(fn (): array => static::packageOptions())
                    ->searchable()
                    ->hidden($isFixedGroup)
                    ->required(fn (Get $get): bool => ! $isFixedGroup($get) && BookingFormState::bookingType($get('booking_type'))->requiresPackage())
                    ->helperText('Choosing a package fills the details below and replaces the inclusion/exclusion lists.')
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, $state) => static::fillFromPackage($get, $set, $state)),
                TextInput::make('trip_name')->required()->maxLength(255),
                DatePicker::make('start_date')
                    ->label('Trip start date')
                    ->required(fn (Get $get): bool => ! $isFixedGroup($get))
                    ->rules(fn (string $operation): array => $operation === 'create' ? ['after:today'] : [])
                    ->validationMessages(['after' => 'The trip must start in the future.'])
                    ->disabled($isFixedGroup)
                    ->dehydrated()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::fillEndDate($get, $set)),
                TextInput::make('duration_days')
                    ->label('Days')
                    ->numeric()->integer()->minValue(1)
                    ->required()
                    ->disabled($isFixedGroup)
                    ->dehydrated()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::fillEndDate($get, $set)),
                DatePicker::make('end_date')
                    ->label('Trip end date')
                    ->helperText('Start date + days.')
                    ->disabled()
                    ->dehydrated(),
                TextInput::make('pax_count')
                    ->label('Travellers (pax)')
                    ->numeric()->integer()->minValue(1)->required()->default(1)
                    ->helperText(fn (Get $get): ?string => static::seatsHint($get('fixed_departure_id')))
                    ->live(onBlur: true),
                MoneyInput::make('per_person_price')
                    ->label('Price per person')
                    ->minValue(0)
                    ->default(0)
                    ->helperText(fn (Get $get): string => $isFixedGroup($get)
                        ? 'Set by the departure. Use a manual adjustment below for a special rate.'
                        : 'From the package. Change it only for a negotiated rate.')
                    ->readOnly($isFixedGroup)
                    ->live(onBlur: true),
                Select::make('status')->options(BookingStatus::class)->default(BookingStatus::Pending->value)->required(),
                Textarea::make('description')->rows(3)->columnSpanFull(),
                Textarea::make('customer_notes')
                    ->label('Customer\'s note to staff')
                    ->rows(3)
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit')
                    ->helperText('Read-only — written by the customer from their portal.')
                    ->columnSpanFull(),
            ])
            ->columns(3)
            ->columnSpanFull();
    }

    /**
     * "Inclusions" lists what the package covers — untick an item to take it out (and refund it).
     * "Exclusions" lists what it doesn't — tick an item to add it to this trip (and charge it).
     * Both accept custom items for this booking only.
     */
    private static function lineItemsSection(string $statePath): Section
    {
        $isInclusions = $statePath === BookingFormState::INCLUSIONS;

        return Section::make($isInclusions ? 'Inclusions' : 'Exclusions')
            ->description($isInclusions
                ? 'Covered by the package price. Untick an item this customer doesn\'t need — the price drops by its cost.'
                : 'Not covered by the package price. Tick an item to add it to this trip — the price goes up by its cost.')
            ->schema([
                Repeater::make($statePath)
                    ->label('')
                    ->schema([
                        Hidden::make('default_included'),
                        Hidden::make('is_custom')->default(true),
                        Hidden::make('description'),
                        Select::make('include_exclude_id')
                            ->label('From catalog')
                            ->options(fn (): array => IncludeExclude::query()->orderBy('sort_order')->pluck('title', 'id')->all())
                            ->searchable()
                            ->placeholder('Custom item')
                            ->visible(fn (Get $get): bool => (bool) $get('is_custom'))
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state): void {
                                $item = filled($state) ? IncludeExclude::query()->find($state) : null;

                                if ($item !== null) {
                                    $set('title', $item->title);
                                    $set('description', $item->description);
                                    $set('unit_price', Money::toDecimal($item->unit_price));
                                    $set('pricing_unit', $item->pricing_unit?->value);
                                }
                            }),
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->readOnly(fn (Get $get): bool => ! $get('is_custom')),
                        Toggle::make('selected')
                            ->label($isInclusions ? 'Included' : 'Add to trip')
                            ->default($isInclusions)
                            ->inline(false)
                            ->live(),
                        TextInput::make('unit_price')
                            ->label('Unit price')
                            ->numeric()->step(0.01)->minValue(0)->default(0)
                            ->prefix(fn (): string => BookingFormState::currency())
                            ->live(onBlur: true),
                        Select::make('pricing_unit')
                            ->label('Per')
                            ->options(PricingUnit::class)
                            ->default(PricingUnit::PerPerson->value)
                            ->selectablePlaceholder(false)
                            ->live(),
                        TextEntry::make('price_effect')
                            ->label('Price effect')
                            ->state(function (Get $get): string {
                                $delta = BookingFormState::rowDelta([
                                    'selected' => $get('selected'),
                                    'default_included' => $get('default_included'),
                                    'unit_price' => $get('unit_price'),
                                    'pricing_unit' => $get('pricing_unit'),
                                ], (int) $get('../../pax_count'), (int) $get('../../duration_days'));

                                return match (true) {
                                    $delta > 0 => '+ '.Money::format($delta, BookingFormState::currency()),
                                    $delta < 0 => '− '.Money::format(-$delta, BookingFormState::currency()),
                                    default => 'In package price',
                                };
                            })
                            ->color(fn (string $state): string => str_starts_with($state, '+') ? 'warning' : (str_starts_with($state, '−') ? 'success' : 'gray'))
                            ->badge(),
                    ])
                    ->itemLabel(fn (array $state): ?string => ($state['title'] ?? null) ?: 'New item')
                    ->addActionLabel($isInclusions ? 'Add inclusion' : 'Add exclusion')
                    ->afterStateHydrated(function (Repeater $component, ?Booking $record) use ($statePath): void {
                        $component->state(BookingFormState::stateForBooking($record)[$statePath]);
                    })
                    ->dehydrated(false)
                    ->reorderable(false)
                    ->collapsible()
                    ->defaultItems(0)
                    ->columns(6)
                    ->columnSpanFull(),
            ])
            ->collapsible()
            ->columnSpanFull();
    }

    /**
     * Staff-added add-ons are approved by default and count toward the total straight away; a
     * customer's portal request only counts once it is approved here.
     */
    private static function addonsSection(): Section
    {
        $linePrice = fn (Get $get, Set $set) => $set('price', round(((float) $get('unit_price')) * max(1, (int) $get('quantity')), 2));

        return Section::make('Add-ons')
            ->description('Extra services for this booking. Services linked to the package are listed first, with the package\'s price.')
            ->schema([
                Repeater::make('addons')
                    ->relationship('addons')
                    ->label('')
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [
                        ...$data,
                        'added_by' => optional(auth('tenant')->user())->email ?? 'staff',
                    ])
                    ->schema([
                        Select::make('service_id')
                            ->label('Service')
                            ->options(fn (Get $get): array => static::serviceOptions($get('../../package_id')))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, $state) use ($linePrice): void {
                                $service = filled($state) ? Service::query()->find($state) : null;
                                $packageId = $get('../../package_id');
                                $set('unit_price', Money::toDecimal($service?->unitPriceFor(filled($packageId) ? (int) $packageId : null) ?? 0));
                                $linePrice($get, $set);
                            }),
                        TextInput::make('quantity')->label('Quantity')->numeric()->integer()->minValue(1)->default(1)->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated($linePrice),
                        MoneyInput::make('unit_price')->label('Unit price')->minValue(0)->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated($linePrice),
                        MoneyInput::make('price')->label('Line total')->minValue(0)->readOnly(),
                        Select::make('status')->options([
                            'requested' => 'Requested',
                            'approved' => 'Approved',
                            'booked' => 'Booked',
                            'cancelled' => 'Cancelled',
                        ])->default('approved')->required()->live(),
                    ])
                    ->itemLabel(fn (array $state): ?string => filled($state['service_id'] ?? null)
                        ? Service::query()->whereKey($state['service_id'])->value('name')
                        : 'Add-on')
                    ->addActionLabel('Add add-on')
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->columns(5)
                    ->columnSpanFull(),
            ])
            ->collapsible()
            ->columnSpanFull();
    }

    private static function documentRequirementsSection(): Section
    {
        return Section::make('Travel document requirements')
            ->description('Customers are asked to upload only the required documents. Defaults come from the package. "Other documents" is always optional — customers can add up to '.BookingDocument::MAX_OTHER_FILES.' extra files.')
            ->schema(collect(DocumentType::requirable())
                ->map(fn (DocumentType $type): Toggle => Toggle::make("document_requirements.{$type->value}")
                    ->label($type->getLabel())
                    ->onIcon('heroicon-m-check')
                    ->helperText(fn ($state): string => $state ? 'Required' : 'Optional')
                    ->live()
                    ->default(DocumentType::defaultRequirements()[$type->value])
                    ->afterStateHydrated(function (Toggle $component, ?Booking $record) use ($type): void {
                        if ($record !== null) {
                            $component->state($record->documentRequirements()[$type->value] ?? false);
                        }
                    }))
                ->all())
            ->columns(5)
            ->collapsible()
            ->columnSpanFull();
    }

    private static function priceSummarySection(): Section
    {
        return Section::make('Price summary')
            ->description('Recalculated as you edit. Saved totals are recalculated again on the server.')
            ->schema([
                TextEntry::make('price_breakdown')
                    ->hiddenLabel()
                    ->state(fn ($livewire): HtmlString => static::renderQuote($livewire->data ?? []))
                    ->html()
                    ->columnSpanFull(),
                MoneyInput::make('manual_adjustment')
                    ->label('Manual adjustment')
                    ->helperText('Negative for a discount, positive for a surcharge.')
                    ->default(0)
                    ->live(onBlur: true),
                TextInput::make('manual_adjustment_reason')
                    ->label('Reason for adjustment')
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => (float) $get('manual_adjustment') !== 0.0),
            ])
            ->columns(2)
            ->columnSpanFull();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function renderQuote(array $state): HtmlString
    {
        return BookingFormState::quote($state)->toHtml(BookingFormState::currency());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function packageOptions(): array
    {
        return Package::query()
            ->where('status', '!=', 'archived')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Package $package): string => $package->category?->getLabel() ?? 'Other')
            ->map(fn ($packages) => $packages->mapWithKeys(fn (Package $package): array => [$package->id => "{$package->name} ({$package->duration_days} days)"])->all())
            ->all();
    }

    /**
     * Every open, future departure, grouped by package and labelled with dates, price and seats
     * left. The booking's current departure is always listed so an existing booking still shows it.
     *
     * @return array<string, array<int, string>>
     */
    public static function departureOptions(?Booking $record = null): array
    {
        $currency = BookingFormState::currency();

        return FixedDeparture::query()
            ->with('package')
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $open): Builder => $open->where('status', 'open')->whereDate('start_date', '>', today()))
                ->when($record?->fixed_departure_id, fn (Builder $q, int $id): Builder => $q->orWhere('id', $id)))
            ->orderBy('start_date')
            ->get()
            ->groupBy(fn (FixedDeparture $departure): string => $departure->package?->name ?? 'Package')
            ->map(fn ($departures) => $departures->mapWithKeys(fn (FixedDeparture $departure): array => [
                $departure->id => $departure->start_date->format('j M Y').' → '.$departure->end_date->format('j M Y')
                    .' · '.Money::format($departure->perPersonPrice(), $currency).' pp'
                    .' · '.$departure->remainingSlots().' seats left',
            ])->all())
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function serviceOptions(mixed $packageId): array
    {
        $services = Service::query()->where('is_active', true)->orderBy('name')->get();
        $linked = filled($packageId)
            ? Package::query()->find($packageId)?->services()->pluck('services.id')->all() ?? []
            : [];

        $options = ['Recommended for this package' => [], 'All services' => []];

        foreach ($services as $service) {
            $group = in_array($service->id, $linked, true) ? 'Recommended for this package' : 'All services';
            $options[$group][$service->id] = $service->name;
        }

        return array_filter($options);
    }

    private static function fillFromPackage(Get $get, Set $set, mixed $packageId): void
    {
        $package = filled($packageId) ? Package::query()->find($packageId) : null;
        $set('fixed_departure_id', null);

        if ($package !== null) {
            static::applyPackage($get, $set, $package);
        }
    }

    /**
     * Copies a package's details into the form: name, days, price, itinerary, document
     * requirements and its inclusion/exclusion lists.
     */
    private static function applyPackage(Get $get, Set $set, Package $package): void
    {
        $set('trip_name', $package->name);
        $set('duration_days', $package->duration_days);
        $set('per_person_price', Money::toDecimal(app(BookingPricing::class)->defaultPerPersonPrice($package)));
        $set('booked_itinerary', $package->itineraryHtml());
        $set('document_requirements', $package->documentRequirements());

        $rows = BookingFormState::rowsToState(BookingIncludeExclude::rowsFromPackage($package));
        $set(BookingFormState::INCLUSIONS, $rows[BookingFormState::INCLUSIONS]);
        $set(BookingFormState::EXCLUSIONS, $rows[BookingFormState::EXCLUSIONS]);

        static::fillEndDate($get, $set);
    }

    private static function fillFromDeparture(Get $get, Set $set, mixed $departureId): void
    {
        $departure = filled($departureId) ? FixedDeparture::query()->with('package')->find($departureId) : null;

        if ($departure === null || $departure->package === null) {
            return;
        }

        $set('package_id', $departure->package_id);
        static::applyPackage($get, $set, $departure->package);

        $set('start_date', $departure->start_date->toDateString());
        $set('end_date', $departure->end_date->toDateString());
        $set('duration_days', (int) $departure->start_date->diffInDays($departure->end_date) + 1);
        $set('per_person_price', Money::toDecimal(app(BookingPricing::class)->defaultPerPersonPrice($departure->package, $departure)));
    }

    private static function fillEndDate(Get $get, Set $set): void
    {
        $start = $get('start_date');
        $days = (int) $get('duration_days');

        if (filled($start) && $days > 0) {
            $set('end_date', CarbonImmutable::parse($start)->addDays($days - 1)->toDateString());
        }
    }

    private static function seatsHint(mixed $departureId): ?string
    {
        $departure = filled($departureId) ? FixedDeparture::query()->find($departureId) : null;

        return $departure ? "{$departure->remainingSlots()} seats left on this departure (including overbooking buffer)." : null;
    }

    /**
     * Streams one of the booking's private documents to staff. Only files that belong to this
     * booking can be downloaded.
     */
    public static function downloadDocument(?Model $booking, ?string $filePath): ?StreamedResponse
    {
        if (! $booking instanceof Booking || blank($filePath)
            || ! $booking->documents()->where('file_path', $filePath)->exists()
            || ! Storage::disk('local')->exists($filePath)) {
            return null;
        }

        return Storage::disk('local')->download($filePath);
    }

    /**
     * Applies the booking-type rules to form data before it is saved (see BookingSchedule).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareFormData(array $data): array
    {
        return app(BookingSchedule::class)->normalize($data, 'data.');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('trip_name')->searchable()->sortable()->weight('bold')
                ->description(fn (Booking $record): ?string => $record->booking_type?->getLabel()),
            TextColumn::make('customer.name')->label('Customer')
                ->description(fn (Booking $record): string => collect([$record->customer?->email, $record->customer?->phone])->filter()->implode(' · '))
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                    'customer',
                    fn (Builder $customer): Builder => $customer->where(fn (Builder $match): Builder => $match
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('email', "%{$search}%")
                        ->orWhereLike('phone', "%{$search}%")),
                )),
            TextColumn::make('booking_type')->label('Type')->badge()->toggleable(),
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
                SelectFilter::make('booking_type')
                    ->label('Booking type')
                    ->options(BookingType::class),
                SelectFilter::make('fixed_departure_id')
                    ->label('Departure')
                    ->options(fn (): array => FixedDeparture::query()->with('package')->orderBy('start_date')->get()
                        ->mapWithKeys(fn (FixedDeparture $departure): array => [
                            $departure->id => ($departure->package?->name ?? 'Departure').' — '.$departure->start_date->format('j M Y'),
                        ])->all())
                    ->searchable(),
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
                            $schedule = app(BookingSchedule::class);
                            $held = $schedule->seatsHeld($record);

                            if ($held[0] !== null) {
                                app(FixedDepartureCapacity::class)->release($held[0], $held[1]);
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
