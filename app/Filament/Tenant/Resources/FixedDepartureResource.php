<?php
namespace App\Filament\Tenant\Resources;
use App\Filament\Tenant\Resources\FixedDepartureResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use BackedEnum;
use UnitEnum;
use App\Models\FixedDeparture;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
class FixedDepartureResource extends Resource
{
 protected static ?string $model = FixedDeparture::class;
 protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';
 protected static UnitEnum|string|null $navigationGroup = 'Catalog';
 protected static ?int $navigationSort = 3;
 public static function form(Schema $schema): Schema { return $schema->components([Select::make('package_id')->relationship('package','name')->searchable()->preload()->required(),DatePicker::make('start_date')->required(),DatePicker::make('end_date')->required()->afterOrEqual('start_date'),TextInput::make('total_slots')->numeric()->integer()->minValue(1)->required(),TextInput::make('overbooking_buffer')->numeric()->integer()->minValue(0)->default(0)->required(),TextInput::make('price_override')->numeric()->integer()->minValue(0),Select::make('status')->options(['open'=>'Open','full'=>'Full','closed'=>'Closed','cancelled'=>'Cancelled'])->default('open')->required()]); }
 public static function table(Table $table): Table { return $table->columns([TextColumn::make('package.name')->label('Package')->sortable(),TextColumn::make('start_date')->date()->sortable(),TextColumn::make('end_date')->date(),TextColumn::make('total_slots'),TextColumn::make('booked_slots'),TextColumn::make('status')->badge()])->defaultSort('start_date')->recordActions([EditAction::make(),DeleteAction::make()]); }
 public static function getPages(): array { return ['index'=>Pages\ListFixedDepartures::route('/'),'create'=>Pages\CreateFixedDeparture::route('/create'),'edit'=>Pages\EditFixedDeparture::route('/{record}/edit')]; }
}


