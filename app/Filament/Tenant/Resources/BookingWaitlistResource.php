<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\BookingWaitlistResource\Pages;
use App\Models\BookingWaitlist;
use App\Models\FixedDeparture;
use App\Services\WaitlistNotifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Staff view of fixed-departure waitlists. Entries only ever come from visitors (public API), so
 * there's no create/edit — staff notify an entry when seats open up, or close it out.
 */
class BookingWaitlistResource extends Resource
{
    protected static ?string $model = BookingWaitlist::class;

    protected static ?string $navigationLabel = 'Waitlist';

    protected static ?string $modelLabel = 'waitlist entry';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-queue-list';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['fixedDeparture.package', 'customer', 'notifiedByStaff']))
            ->columns([
                TextColumn::make('fixedDeparture.package.name')->label('Package')->searchable(),
                TextColumn::make('fixedDeparture.start_date')->label('Departs')->date()->sortable(),
                TextColumn::make('position')->label('#')->sortable(),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('customer.email')->label('Email')->searchable(),
                TextColumn::make('pax_requested')->label('Pax'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'waiting' => 'warning',
                        'notified' => 'info',
                        'converted' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('notified_at')->dateTime()->placeholder('—')
                    ->description(fn (BookingWaitlist $record): ?string => $record->notifiedByStaff?->name),
            ])
            ->defaultSort('position')
            ->filters([
                SelectFilter::make('status')->options([
                    'waiting' => 'Waiting',
                    'notified' => 'Notified',
                    'converted' => 'Converted',
                    'removed' => 'Removed',
                ])->default('waiting'),
                SelectFilter::make('fixed_departure_id')
                    ->label('Departure')
                    ->options(fn (): array => FixedDeparture::query()
                        ->with('package')
                        ->whereDate('start_date', '>=', today())
                        ->orderBy('start_date')
                        ->get()
                        ->mapWithKeys(fn (FixedDeparture $departure): array => [
                            $departure->id => "{$departure->package->name} — {$departure->start_date->toFormattedDateString()}",
                        ])
                        ->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('notify')
                        ->label('Notify: seats available')
                        ->icon('heroicon-o-envelope')
                        ->visible(fn (BookingWaitlist $record): bool => $record->status === 'waiting' && Gate::allows('update', $record))
                        ->requiresConfirmation()
                        ->modalDescription(fn (BookingWaitlist $record): string => "Email {$record->customer?->email} that seats are available on this departure?")
                        ->action(function (BookingWaitlist $record): void {
                            if (! app(WaitlistNotifier::class)->notify($record, auth('tenant')->user())) {
                                Notification::make()->danger()->title('Email failed to send')->body('Check your email settings.')->send();

                                return;
                            }

                            Notification::make()->success()->title('Customer notified')->send();
                        }),
                    Action::make('markConverted')
                        ->label('Mark as booked')
                        ->icon('heroicon-o-check-circle')
                        ->visible(fn (BookingWaitlist $record): bool => in_array($record->status, ['waiting', 'notified'], true) && Gate::allows('update', $record))
                        ->action(fn (BookingWaitlist $record) => $record->update(['status' => 'converted'])),
                    Action::make('remove')
                        ->label('Remove from waitlist')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->visible(fn (BookingWaitlist $record): bool => in_array($record->status, ['waiting', 'notified'], true) && Gate::allows('update', $record))
                        ->requiresConfirmation()
                        ->action(fn (BookingWaitlist $record) => $record->update(['status' => 'removed'])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookingWaitlists::route('/'),
        ];
    }
}
