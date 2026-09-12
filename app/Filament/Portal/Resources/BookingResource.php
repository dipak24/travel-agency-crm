<?php

namespace App\Filament\Portal\Resources;

use App\Filament\Portal\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\BookingDocument;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationLabel = 'My Bookings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('customer_id', auth('customer')->id());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('trip_name')->label('Trip')->searchable()->sortable(),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')->date()->sortable(),
                TextColumn::make('pax_count')->label('Travelers'),
                TextColumn::make('total_amount')->label('Total')->money(fn (Booking $record): string => $record->tenant?->currency ?? 'USD', divideBy: 100)->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('start_date', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Trip overview')
                ->schema([
                    TextEntry::make('trip_name')->label('Trip'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('start_date')->date(),
                    TextEntry::make('end_date')->date(),
                    TextEntry::make('pax_count')->label('Travelers'),
                    TextEntry::make('total_amount')->label('Total')->money(fn (Booking $record): string => $record->tenant?->currency ?? 'USD', divideBy: 100),
                    TextEntry::make('description')->columnSpanFull(),
                ])
                ->columns(3),
            Section::make('Itinerary')
                ->schema([
                    TextEntry::make('booked_itinerary')->label('')->html(),
                ])
                ->visible(fn (Booking $record): bool => filled($record->booked_itinerary)),
            Section::make('What\'s included / excluded')
                ->schema([
                    RepeatableEntry::make('includeExcludes')
                        ->label('')
                        ->schema([
                            TextEntry::make('type')->badge(),
                            TextEntry::make('title'),
                            TextEntry::make('description'),
                        ])
                        ->columns(3)
                        ->contained(false),
                ])
                ->visible(fn (Booking $record): bool => $record->includeExcludes->isNotEmpty()),
            Section::make('Travelers')
                ->schema([
                    RepeatableEntry::make('travelers')
                        ->label('')
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('dob')->label('Date of birth')->date(),
                            TextEntry::make('document_status')->badge(),
                        ])
                        ->columns(3)
                        ->contained(false),
                ])
                ->visible(fn (Booking $record): bool => $record->travelers->isNotEmpty()),
            Section::make('Documents')
                ->schema([
                    RepeatableEntry::make('documents')
                        ->label('')
                        ->schema([
                            TextEntry::make('doc_type')->label('Type')->badge(),
                            TextEntry::make('status')->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'approved' => 'success',
                                    'rejected' => 'danger',
                                    default => 'gray',
                                }),
                            TextEntry::make('rejection_reason')->label('Reason')
                                ->visible(fn (BookingDocument $record): bool => $record->status === 'rejected'),
                        ])
                        ->columns(3)
                        ->contained(false),
                ])
                ->visible(fn (Booking $record): bool => $record->documents->isNotEmpty()),
            Section::make('Add-ons')
                ->schema([
                    RepeatableEntry::make('addons')
                        ->label('')
                        ->schema([
                            TextEntry::make('service.name')->label('Service'),
                            TextEntry::make('quantity'),
                            TextEntry::make('price')->label('Price')->money(fn (): string => auth('customer')->user()->tenant?->currency ?? 'USD', divideBy: 100),
                            TextEntry::make('status')->badge(),
                        ])
                        ->columns(4)
                        ->contained(false),
                ])
                ->visible(fn (Booking $record): bool => $record->addons->isNotEmpty()),
            Section::make('Your note to staff')
                ->schema([
                    TextEntry::make('customer_notes')->label('')->placeholder('You haven\'t left a note yet.'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }
}
