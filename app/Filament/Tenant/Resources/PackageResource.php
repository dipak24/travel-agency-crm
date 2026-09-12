<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\PackageResource\Pages;
use App\Models\Package;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
                Section::make('Package details')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('package_code')
                            ->label('Package code')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Use a URL-friendly value. It must be unique within this agency.'),
                        TextInput::make('duration_days')
                            ->label('Duration (days)')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->integer(),
                        MoneyInput::make('base_price')
                            ->label('Base price')
                            ->required()
                            ->minValue(0),
                        MoneyInput::make('sales_price')
                            ->label('Sales price')
                            ->required()
                            ->minValue(0),
                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'published' => 'Published',
                                'archived' => 'Archived',
                            ])
                            ->default('draft')
                            ->required(),
                        Toggle::make('is_public')
                            ->label('Visible on public website')
                            ->default(false),
                        Textarea::make('description')
                            ->columnSpanFull()
                            ->rows(4),
                        Repeater::make('itinerary')
                            ->label('Itinerary')
                            ->schema([
                                TextInput::make('title')
                                    ->label('Day title')
                                    ->required()
                                    ->maxLength(255),
                                Textarea::make('description')
                                    ->required()
                                    ->rows(3),
                            ])
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->reorderable()
                            ->addActionLabel('Add itinerary day')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
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
                    ->sortable(),
                IconColumn::make('is_public')
                    ->label('Public')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ]),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (Package $record): bool => $record->status !== 'published')
                    ->action(fn (Package $record) => $record->update(['status' => 'published'])),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Package $record): bool => $record->status !== 'archived')
                    ->action(fn (Package $record) => $record->update(['status' => 'archived'])),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()->before(static fn (Package $record, DeleteAction $action) => static::guardAgainstDeletingPackageWithBookings($record, $action)),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function guardAgainstDeletingPackageWithBookings(Package $record, DeleteAction $action): void
    {
        if ($record->bookings()->exists() || $record->fixedDepartures()->where('booked_slots', '>', 0)->exists()) {
            Notification::make()
                ->danger()
                ->title('Cannot delete this package')
                ->body('This package has bookings (directly, or against one of its fixed departures). Archive it instead.')
                ->send();

            $action->cancel();
        }
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
