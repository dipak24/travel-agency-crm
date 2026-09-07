<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\PromoCodeResource\Pages;
use App\Models\PromoCode;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static ?string $navigationLabel = 'Promo Codes';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-ticket';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(255),
            Select::make('discount_type')->options([
                'percent' => 'Percent',
                'flat' => 'Flat amount (minor units)',
            ])->required(),
            TextInput::make('discount_value')->numeric()->integer()->minValue(0)->required()->default(0),
            TextInput::make('usage_limit')->numeric()->integer()->minValue(1)->helperText('Leave blank for unlimited uses.'),
            TextInput::make('used_count')->numeric()->integer()->minValue(0)->disabled()->dehydrated(false)->hiddenOn('create'),
            DateTimePicker::make('valid_from')->native(false),
            DateTimePicker::make('valid_until')->native(false),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('discount_type')->badge(),
            TextColumn::make('discount_value')->numeric(),
            TextColumn::make('usage_limit')->placeholder('Unlimited'),
            TextColumn::make('used_count')->label('Used'),
            TextColumn::make('valid_until')->label('Expires')->dateTime()->placeholder('Never')->sortable(),
            IconColumn::make('is_active')->boolean(),
        ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoCodes::route('/'),
            'create' => Pages\CreatePromoCode::route('/create'),
            'edit' => Pages\EditPromoCode::route('/{record}/edit'),
        ];
    }
}
