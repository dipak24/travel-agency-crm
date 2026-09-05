<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationLabel = 'Bookings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Booking details')
                ->schema([
                    Select::make('customer_id')
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm(CustomerResource::quickCreateSchema())
                        ->createOptionAction(fn (Action $action) => $action
                            ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('create', Customer::class))
                            ->modalHeading('Add guest customer')),
                    TextInput::make('trip_name')->required()->maxLength(255),
                    Textarea::make('description')->rows(4),
                    TextInput::make('pax_count')->numeric()->integer()->minValue(1)->required()->default(1),
                    TextInput::make('total_amount')->label('Total (minor units)')->numeric()->integer()->minValue(0)->required()->default(0),
                    Select::make('status')->options([
                        'pending' => 'Pending',
                        'confirmed' => 'Confirmed',
                        'ongoing' => 'Ongoing',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ])->required(),
                ])
                ->columns(1),
            Section::make('Travel documents')
                ->schema([
                    Repeater::make('documents')
                        ->relationship('documents')
                        ->schema([
                            Select::make('doc_type')->options([
                                'passport' => 'Passport',
                                'pp_photo' => 'PP size photo',
                                'visa' => 'Visa',
                                'insurance' => 'Insurance',
                                'other' => 'Other documents',
                            ])->required(),
                            FileUpload::make('file_path')
                                ->label('Document file')
                                ->disk('local')
                                ->directory('booking-documents')
                                ->visibility('private')
                                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'])
                                ->required(),
                            Select::make('status')->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])->default('pending')->required(),
                            TextInput::make('rejection_reason')->label('Rejection reason')->maxLength(255),
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['doc_type'] ?? 'Document')
                        ->addActionLabel('Add document')
                        ->reorderable(false)
                        ->defaultItems(0),
                ]),
            Section::make('Add-ons')
                ->schema([
                    Repeater::make('addons')
                        ->relationship('addons')
                        ->schema([
                            Select::make('service_id')->relationship('service', 'name')->searchable()->preload()->required(),
                            TextInput::make('quantity')->label('Quantity')->numeric()->integer()->minValue(1)->default(1)->required(),
                            TextInput::make('price')->label('Price (minor units)')->numeric()->integer()->minValue(0)->required(),
                            Select::make('status')->options([
                                'requested' => 'Requested',
                                'approved' => 'Approved',
                                'booked' => 'Booked',
                                'cancelled' => 'Cancelled',
                            ])->default('requested')->required(),
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['service_id'] ? 'Add-on' : 'Add-on')
                        ->addActionLabel('Add add-on')
                        ->reorderable(false)
                        ->defaultItems(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('trip_name')->searchable()->sortable(),
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            TextColumn::make('fixedDeparture.start_date')->label('Departure')->date()->sortable(),
            TextColumn::make('pax_count')->label('Pax'),
            TextColumn::make('total_amount')->label('Total (minor units)')->numeric(),
            TextColumn::make('status')->badge()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
