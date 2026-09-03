<?php
namespace App\Filament\Tenant\Resources;
use App\Filament\Tenant\Resources\ServiceAvailabilityResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use BackedEnum;
use UnitEnum;
use App\Models\ServiceAvailability;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
class ServiceAvailabilityResource extends Resource
{
 protected static ?string $model = ServiceAvailability::class;
 protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';
 protected static UnitEnum|string|null $navigationGroup = 'Catalog';
 protected static ?int $navigationSort = 6;
 public static function form(Schema $schema): Schema { return $schema->components([Select::make('service_id')->relationship('service','name')->searchable()->preload()->required(),DatePicker::make('date')->required(),TextInput::make('total_slots')->numeric()->integer()->minValue(1)->required()]); }
 public static function table(Table $table): Table { return $table->columns([TextColumn::make('service.name')->label('Service')->sortable(),TextColumn::make('date')->date()->sortable(),TextColumn::make('total_slots'),TextColumn::make('booked_slots'),TextColumn::make('remaining_slots')->state(fn (ServiceAvailability $record): int => $record->remainingSlots())])->defaultSort('date')->recordActions([EditAction::make(),DeleteAction::make()]); }
 public static function getPages(): array { return ['index'=>Pages\ListServiceAvailabilities::route('/'),'create'=>Pages\CreateServiceAvailability::route('/create'),'edit'=>Pages\EditServiceAvailability::route('/{record}/edit')]; }
}


