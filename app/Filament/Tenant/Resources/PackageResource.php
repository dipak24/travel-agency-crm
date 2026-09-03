<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\PackageResource\Pages;
use App\Models\Package;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use BackedEnum;
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
            ->components([
                Section::make('Package details')
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
                        TextInput::make('base_price')
                            ->label('Base price (minor units)')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->integer(),
                        TextInput::make('sales_price')
                            ->label('Sales price (minor units)')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->integer(),
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
                    ])
                    ->columns(2),
                Section::make('Itinerary')
                    ->schema([
                        Repeater::make('itinerary')
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
                    ]),
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
                    ->numeric(),
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
            ->defaultSort('name');
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
