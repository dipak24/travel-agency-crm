<?php

namespace App\Filament\Tenant\Resources;

use App\Enums\PackageCategory;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\PackageResource\PackageFormState;
use App\Filament\Tenant\Resources\PackageResource\Pages;
use App\Filament\Tenant\Resources\PackageResource\RelationManagers\DiscountTiersRelationManager;
use App\Filament\Tenant\Resources\PackageResource\RelationManagers\FixedDeparturesRelationManager;
use App\Models\Package;
use App\Support\PublicBookingLinks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-map';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Package')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Details')
                            ->icon('heroicon-o-map')
                            ->schema([static::detailsSection()]),
                        Tab::make('Inclusions & exclusions')
                            ->icon('heroicon-o-check-circle')
                            ->badge(fn (Get $get): ?int => static::tickedCount($get(PackageFormState::INCLUSIONS)))
                            ->schema([static::inclusionsList()]),
                        Tab::make('Add-on services')
                            ->icon('heroicon-o-sparkles')
                            ->badge(fn (Get $get): ?int => static::tickedCount($get(PackageFormState::SERVICES)))
                            ->schema([static::servicesList()]),
                    ]),
            ]);
    }

    private static function detailsSection(): Section
    {
        return Section::make('Package details')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if (blank($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('package_code')
                    ->label('Package code')
                    ->required()
                    ->maxLength(30)
                    ->regex('/^[A-Za-z0-9_-]+$/')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', auth('tenant')->user()->tenant_id))
                    ->validationMessages(['regex' => 'Use only letters, numbers, dashes and underscores.'])
                    ->helperText('Short code used in the public booking link, e.g. EBC-14.'),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Filled from the name. Used by the public API; it must be unique within this agency.'),
                Select::make('category')
                    ->options(PackageCategory::class)
                    ->default(PackageCategory::Trek->value)
                    ->required(),
                TextInput::make('duration_days')
                    ->label('Duration (days)')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->integer(),
                MoneyInput::make('base_price')
                    ->label('Cost price per person')
                    ->helperText('Internal cost, not shown to customers.')
                    ->required()
                    ->minValue(0),
                MoneyInput::make('sales_price')
                    ->label('Price per person')
                    ->helperText('Covers every item marked "included in the price".')
                    ->required()
                    ->minValue(0),
                Select::make('status')
                    ->options(fn (?Package $record): array => $record?->status === 'archived'
                        ? static::statusOptions()
                        : array_diff_key(static::statusOptions(), ['archived' => true]))
                    ->default('draft')
                    ->helperText('Only published packages are shown on your website and booking pages.')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull()
                    ->rows(4),
                RichEditor::make('itinerary')
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'strike'],
                        ['h2', 'h3'],
                        ['bulletList', 'orderedList', 'blockquote'],
                        ['undo', 'redo'],
                    ])
                    ->helperText('Copied into each new booking, where it can be changed for that booking only.')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * The whole Include / Exclude catalog: tick what belongs to this package, choose whether it is
     * in the price, and set this package's price. Titles and units stay as in the catalog.
     */
    private static function inclusionsList(): Repeater
    {
        return Repeater::make(PackageFormState::INCLUSIONS)
            ->hiddenLabel()
            ->helperText('Every item from the Include / Exclude catalog. Tick the ones that belong to this package. The price is copied from the catalog and can be changed for this package; new bookings copy these items and prices.')
            ->table([
                TableColumn::make('Use')->width('70px'),
                TableColumn::make('Item'),
                TableColumn::make('On this package')->width('240px'),
                TableColumn::make('Price')->width('180px'),
                TableColumn::make('Per')->width('170px'),
            ])
            ->schema([
                Hidden::make('include_exclude_id'),
                Toggle::make('selected')->hiddenLabel()->live(),
                TextEntry::make('title_label')->hiddenLabel()->state(fn (Get $get): ?string => $get('title')),
                Select::make('placement')
                    ->hiddenLabel()
                    ->options(['include' => 'Included in the price', 'exclude' => 'Excluded (optional extra)'])
                    ->selectablePlaceholder(false)
                    ->disabled(fn (Get $get): bool => ! $get('selected')),
                TextInput::make('unit_price')
                    ->hiddenLabel()
                    ->numeric()->step(0.01)->minValue(0)
                    ->prefix(fn (): string => static::currency())
                    ->required(fn (Get $get): bool => (bool) $get('selected'))
                    ->disabled(fn (Get $get): bool => ! $get('selected')),
                TextEntry::make('pricing_unit_label')->hiddenLabel()->state(fn (Get $get): ?string => $get('pricing_unit'))->color('gray'),
            ])
            ->afterStateHydrated(fn (Repeater $component, ?Package $record) => $component->state(PackageFormState::inclusionState($record)))
            ->dehydrated(false)
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->columnSpanFull();
    }

    /**
     * Every service: tick the ones offered with this package and set this package's price.
     */
    private static function servicesList(): Repeater
    {
        return Repeater::make(PackageFormState::SERVICES)
            ->hiddenLabel()
            ->helperText('Every active service. Tick the ones to offer with this package; they are listed first when booking it. The price is copied from the service and can be changed for this package.')
            ->table([
                TableColumn::make('Offer')->width('70px'),
                TableColumn::make('Service'),
                TableColumn::make('Price')->width('180px'),
                TableColumn::make('Per')->width('170px'),
                TableColumn::make('Featured')->width('100px'),
            ])
            ->schema([
                Hidden::make('service_id'),
                Toggle::make('selected')->hiddenLabel()->live(),
                TextEntry::make('name_label')
                    ->hiddenLabel()
                    ->state(fn (Get $get): ?string => $get('name'))
                    ->helperText(fn (Get $get): ?string => $get('category')),
                TextInput::make('unit_price')
                    ->hiddenLabel()
                    ->numeric()->step(0.01)->minValue(0)
                    ->prefix(fn (): string => static::currency())
                    ->required(fn (Get $get): bool => (bool) $get('selected'))
                    ->disabled(fn (Get $get): bool => ! $get('selected')),
                TextEntry::make('pricing_unit_label')->hiddenLabel()->state(fn (Get $get): ?string => $get('pricing_unit'))->color('gray'),
                Toggle::make('is_featured')->hiddenLabel()->disabled(fn (Get $get): bool => ! $get('selected')),
            ])
            ->afterStateHydrated(fn (Repeater $component, ?Package $record) => $component->state(PackageFormState::serviceState($record)))
            ->dehydrated(false)
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->columnSpanFull();
    }

    /**
     * @param  array<array-key, array<string, mixed>>|null  $rows
     */
    private static function tickedCount(?array $rows): ?int
    {
        $count = collect($rows ?? [])->filter(fn (array $row): bool => (bool) ($row['selected'] ?? false))->count();

        return $count > 0 ? $count : null;
    }

    private static function currency(): string
    {
        return auth('tenant')->user()?->tenant?->currency ?? 'USD';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('package_code')
                    ->label('Code')
                    ->searchable(),
                TextColumn::make('duration_days')
                    ->label('Days')
                    ->sortable(),
                TextColumn::make('sales_price')
                    ->label('Sales price')
                    ->money(fn (): string => auth('tenant')->user()->tenant->currency ?? 'USD', divideBy: 100),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'archived' => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_diff_key(static::statusOptions(), ['archived' => true])),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('bookingLink')
                        ->label('Booking link')
                        ->icon('heroicon-o-link')
                        ->visible(fn (Package $record): bool => $record->isPublished())
                        ->modalHeading('Booking link for your website')
                        ->modalDescription('Add these to your marketing site. The booking page takes ?start_date=YYYY-MM-DD&pax=2 to pre-fill the form.')
                        ->schema(fn (Package $record): array => [
                            TextEntry::make('booking_url')
                                ->label('Book on own dates')
                                ->state(PublicBookingLinks::package(auth('tenant')->user()->tenant, $record))
                                ->helperText('Needs "Trip booking" switched on in Website settings. Click to copy.')
                                ->copyable()
                                ->copyMessage('Booking link copied')
                                ->fontFamily('mono'),
                            TextEntry::make('widget_snippet')
                                ->label('Group departures widget')
                                ->state(PublicBookingLinks::widgetSnippet(auth('tenant')->user()->tenant, $record->package_code))
                                ->helperText('Lists this package\'s upcoming departures with a Book button. Needs "Group departure joining" switched on. Click to copy.')
                                ->copyable()
                                ->copyMessage('Widget code copied')
                                ->fontFamily('mono'),
                        ])
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    Action::make('publish')
                        ->label('Publish')
                        ->icon('heroicon-o-eye')
                        ->color('gray')
                        ->visible(fn (Package $record): bool => $record->status === 'draft')
                        ->action(fn (Package $record) => $record->update(['status' => 'published'])),
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('The package is taken off your website and moved to the Archived list. Existing bookings are not changed.')
                        ->visible(fn (Package $record): bool => $record->status !== 'archived')
                        ->action(fn (Package $record) => $record->update(['status' => 'archived'])),
                    Action::make('restore')
                        ->label('Restore')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('gray')
                        ->visible(fn (Package $record): bool => $record->status === 'archived')
                        ->action(fn (Package $record) => $record->update(['status' => 'draft'])),
                    DeleteAction::make()
                        ->label('Delete permanently')
                        ->modalHeading('Delete package permanently')
                        ->visible(fn (Package $record): bool => $record->status === 'archived')
                        ->before(static fn (Package $record, DeleteAction $action) => static::guardAgainstDeletingPackageWithBookings($record, $action)),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->size('sm')
                    ->tooltip('Actions'),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'draft' => 'Draft',
            'published' => 'Published',
            'archived' => 'Archived',
        ];
    }

    public static function guardAgainstDeletingPackageWithBookings(Package $record, DeleteAction $action): void
    {
        if ($record->bookings()->exists() || $record->fixedDepartures()->where('booked_slots', '>', 0)->exists()) {
            Notification::make()
                ->danger()
                ->title('Cannot delete this package')
                ->body('This package has bookings (directly, or against one of its fixed departures). so it stays archived.')
                ->send();

            $action->cancel();
        }
    }

    public static function getRelations(): array
    {
        return [
            FixedDeparturesRelationManager::class,
            DiscountTiersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }
}
