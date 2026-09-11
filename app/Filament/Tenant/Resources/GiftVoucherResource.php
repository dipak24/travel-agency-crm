<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\GiftVoucherResource\Pages;
use App\Models\GiftVoucher;
use BackedEnum;
use Filament\Actions\ActionGroup;
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

/**
 * Gift vouchers are bought by the customer (see App\Filament\Portal\Pages\BuyGiftVoucher and
 * App\Services\GiftVoucherPurchase) — tenant staff never manually issue one, so this resource is
 * deliberately view/support-only: no create page, and code/value/currency/issued-to are locked on
 * the edit form since they're tied to the actual purchase invoice, not staff-editable data entry.
 */
class GiftVoucherResource extends Resource
{
    protected static ?string $model = GiftVoucher::class;

    protected static ?string $navigationLabel = 'Gift Vouchers';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-gift';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->disabled(),
            TextInput::make('value')->label('Value (minor units)')->disabled(),
            TextInput::make('currency')->disabled(),
            Select::make('issued_to')
                ->label('Issued to')
                ->relationship('issuedTo', 'name')
                ->disabled(),
            Select::make('status')->options([
                'unredeemed' => 'Unredeemed',
                'partially_redeemed' => 'Partially redeemed',
                'redeemed' => 'Redeemed',
                'expired' => 'Expired',
            ])->required(),
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
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGiftVouchers::route('/'),
            'edit' => Pages\EditGiftVoucher::route('/{record}/edit'),
        ];
    }
}
