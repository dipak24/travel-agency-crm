<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\GiftVoucherResource\Pages;
use App\Models\GiftVoucher;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class GiftVoucherResource extends Resource
{
    protected static ?string $model = GiftVoucher::class;

    protected static ?string $navigationLabel = 'Gift Vouchers';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-gift';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(255),
            TextInput::make('value')->label('Value (minor units)')->numeric()->integer()->minValue(0)->required()->default(0),
            TextInput::make('currency')->default('USD')->required()->length(3),
            Select::make('issued_to')
                ->label('Issued to')
                ->relationship('issuedTo', 'name')
                ->searchable()
                ->preload()
                ->helperText('Optional — leave blank for an unassigned voucher.'),
            Select::make('status')->options([
                'unredeemed' => 'Unredeemed',
                'partially_redeemed' => 'Partially redeemed',
                'redeemed' => 'Redeemed',
                'expired' => 'Expired',
            ])->default('unredeemed')->required(),
            DateTimePicker::make('expires_at')->native(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('value')->numeric(),
            TextColumn::make('currency'),
            TextColumn::make('issuedTo.name')->label('Issued to')->placeholder('—'),
            TextColumn::make('status')->badge(),
            TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('Never')->sortable(),
        ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGiftVouchers::route('/'),
            'create' => Pages\CreateGiftVoucher::route('/create'),
            'edit' => Pages\EditGiftVoucher::route('/{record}/edit'),
        ];
    }
}
