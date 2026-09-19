<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\GiftVoucherResource\Pages;
use App\Models\GiftVoucher;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
            Section::make('Voucher')
                ->schema([
                    TextInput::make('code')->disabled(),
                    MoneyInput::make('value')->label('Value')->disabled(),
                    TextInput::make('currency')->disabled(),
                    Select::make('status')->options([
                        'unredeemed' => 'Unredeemed',
                        'partially_redeemed' => 'Partially redeemed',
                        'redeemed' => 'Redeemed',
                        'expired' => 'Expired',
                    ])->required(),
                    DateTimePicker::make('expires_at')->native(false),
                ])
                ->columns(2),
            Section::make('Purchaser')
                ->description('Who bought this voucher.')
                ->schema([
                    Placeholder::make('purchaser_name')
                        ->label('Name')
                        ->content(fn (GiftVoucher $record): string => $record->sourceInvoice?->purchaserName() ?? $record->sourceInvoice?->customer?->name ?? '—'),
                    Placeholder::make('purchaser_email')
                        ->label('Email')
                        ->content(fn (GiftVoucher $record): string => $record->sourceInvoice?->purchaser_email ?? '—'),
                    Placeholder::make('purchaser_phone')
                        ->label('Phone')
                        ->content(fn (GiftVoucher $record): string => $record->sourceInvoice?->purchaser_phone ?? '—'),
                    Placeholder::make('source_invoice')
                        ->label('Invoice')
                        ->content(fn (GiftVoucher $record): string => $record->sourceInvoice?->invoice_no ?? '—'),
                ])
                ->columns(2)
                ->visibleOn('edit'),
            Section::make('Recipient')
                ->description('Who this voucher was actually sent to — may differ from the purchaser.')
                ->schema([
                    TextInput::make('recipient_first_name')->label('First name')->disabled(),
                    TextInput::make('recipient_last_name')->label('Last name')->disabled(),
                    TextInput::make('recipient_email')->label('Email')->disabled(),
                    TextInput::make('recipient_phone')->label('Mobile')->disabled(),
                    Select::make('issued_to')
                        ->label('Linked customer account')
                        ->relationship('issuedTo', 'name')
                        ->disabled()
                        ->helperText('Only set when the recipient email matches an existing customer account.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('value')->money(fn (GiftVoucher $record): string => $record->currency, divideBy: 100),
            TextColumn::make('currency'),
            TextColumn::make('recipient_name')
                ->label('Recipient')
                ->state(fn (GiftVoucher $record): string => $record->recipientName() ?? '—')
                ->searchable(['recipient_first_name', 'recipient_last_name', 'recipient_email']),
            TextColumn::make('recipient_email')->label('Recipient email')->placeholder('—'),
            TextColumn::make('sourceInvoice.purchaserName')
                ->label('Purchased by')
                ->state(fn (GiftVoucher $record): string => $record->sourceInvoice?->purchaserName() ?? $record->sourceInvoice?->customer?->name ?? '—'),
            TextColumn::make('sourceInvoice.invoice_no')->label('Invoice')->placeholder('—'),
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
