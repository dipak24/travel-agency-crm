<?php
namespace App\Filament\Tenant\Resources;
use App\Filament\Tenant\Resources\GroupDiscountTierResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use BackedEnum;
use UnitEnum;
use App\Models\GroupDiscountTier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
class GroupDiscountTierResource extends Resource
{
 protected static ?string $model = GroupDiscountTier::class;
 protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';
 protected static UnitEnum|string|null $navigationGroup = 'Catalog';
 protected static ?int $navigationSort = 4;
 public static function form(Schema $schema): Schema { return $schema->components([Select::make('package_id')->relationship('package','name')->searchable()->preload(),TextInput::make('min_pax')->numeric()->integer()->minValue(1)->required(),TextInput::make('max_pax')->numeric()->integer()->minValue(1),Select::make('discount_type')->options(['percentage'=>'Percentage','fixed'=>'Fixed amount (minor units)'])->required(),TextInput::make('discount_value')->numeric()->integer()->minValue(0)->required()]); }
 public static function table(Table $table): Table { return $table->columns([TextColumn::make('package.name')->label('Package')->placeholder('All packages'),TextColumn::make('min_pax'),TextColumn::make('max_pax')->placeholder('No limit'),TextColumn::make('discount_type')->badge(),TextColumn::make('discount_value')])->recordActions([EditAction::make(),DeleteAction::make()]); }
 public static function getPages(): array { return ['index'=>Pages\ListGroupDiscountTiers::route('/'),'create'=>Pages\CreateGroupDiscountTier::route('/create'),'edit'=>Pages\EditGroupDiscountTier::route('/{record}/edit')]; }
}


