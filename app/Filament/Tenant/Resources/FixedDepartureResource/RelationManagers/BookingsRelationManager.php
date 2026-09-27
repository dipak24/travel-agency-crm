<?php

namespace App\Filament\Tenant\Resources\FixedDepartureResource\RelationManagers;

use App\Filament\Tenant\Resources\BookingResource;
use App\Models\Booking;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The departure's passenger manifest: every booking on it, with its travellers and payment status.
 */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Bookings on this departure';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('customer')->withCount('travelers'))
            ->columns([
                TextColumn::make('customer.name')->label('Customer')
                    ->description(fn (Booking $record): string => collect([$record->customer?->email, $record->customer?->phone])->filter()->implode(' · ')),
                TextColumn::make('pax_count')->label('Pax'),
                TextColumn::make('travelers_count')->label('Traveller details')
                    ->formatStateUsing(fn (Booking $record, $state): string => "{$state} / {$record->pax_count}"),
                TextColumn::make('total_amount')->label('Total')
                    ->money(fn (): string => auth('tenant')->user()->tenant->currency ?? 'USD', divideBy: 100),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                Action::make('open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Booking $record): string => BookingResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
