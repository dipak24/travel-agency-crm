<?php
namespace App\Filament\Tenant\Resources;
use App\Filament\Tenant\Resources\ServiceResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use BackedEnum;
use UnitEnum;
use App\Models\Service;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
class ServiceResource extends Resource
{
 protected static ?string $model = Service::class;
 protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-plus-circle';
 protected static UnitEnum|string|null $navigationGroup = 'Catalog';
 protected static ?int $navigationSort = 5;
 public static function form(Schema $schema): Schema { return $schema->components([TextInput::make('name')->required()->maxLength(255),Textarea::make('description')->rows(4),TextInput::make('price')->label('Price (minor units)')->numeric()->integer()->minValue(0)->required(),TextInput::make('currency')->default('USD')->required()->length(3),Toggle::make('is_active')->default(true),Toggle::make('has_limited_availability')->label('Limited availability')->default(false)]); }
 public static function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()->sortable(),TextColumn::make('price'),TextColumn::make('currency'),TextColumn::make('is_active')->boolean(),TextColumn::make('has_limited_availability')->label('Limited')->boolean()])->recordActions([EditAction::make(),DeleteAction::make()]); }
 public static function getPages(): array { return ['index'=>Pages\ListServices::route('/'),'create'=>Pages\CreateService::route('/create'),'edit'=>Pages\EditService::route('/{record}/edit')]; }
}


