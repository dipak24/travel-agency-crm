<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Tenant\Resources\FixedDepartureResource\Pages;
use App\Models\FixedDeparture;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class FixedDepartureResource extends Resource
{
    protected static ?string $model = FixedDeparture::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('package_id')->relationship('package', 'name')->searchable()->preload()->required(),
            DatePicker::make('start_date')->required(),
            DatePicker::make('end_date')->required()->afterOrEqual('start_date'),
            TextInput::make('total_slots')->numeric()->integer()->minValue(1)->required(),
            TextInput::make('overbooking_buffer')->numeric()->integer()->minValue(0)->default(0)->required(),
            MoneyInput::make('price_override')->label('Price override')->minValue(0),
            Select::make('status')->options([
                'open' => 'Open',
                'full' => 'Full',
                'closed' => 'Closed',
                'cancelled' => 'Cancelled',
            ])->default('open')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('package.name')->label('Package')->sortable(),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')->date(),
                TextColumn::make('total_slots'),
                TextColumn::make('booked_slots'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('start_date')
            ->recordActions([
                Action::make('closeDeparture')
                    ->label('Close')
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Closing stops any further bookings against this departure.')
                    ->visible(fn (FixedDeparture $record): bool => ! in_array($record->status, ['closed', 'cancelled'], true))
                    ->action(fn (FixedDeparture $record) => $record->update(['status' => 'closed'])),
                Action::make('cancelDeparture')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This marks the departure cancelled. Existing bookings against it are not changed automatically.')
                    ->visible(fn (FixedDeparture $record): bool => $record->status !== 'cancelled')
                    ->action(fn (FixedDeparture $record) => $record->update(['status' => 'cancelled'])),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        ->before(function (FixedDeparture $record, DeleteAction $action): void {
                            if ($record->booked_slots > 0) {
                                Notification::make()
                                    ->danger()
                                    ->title('Cannot delete this departure')
                                    ->body('This departure has bookings against it. Cancel or close it instead.')
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFixedDepartures::route('/'),
            'create' => Pages\CreateFixedDeparture::route('/create'),
            'edit' => Pages\EditFixedDeparture::route('/{record}/edit'),
        ];
    }
}
