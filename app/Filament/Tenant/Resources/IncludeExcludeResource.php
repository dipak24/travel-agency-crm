<?php
namespace App\Filament\Tenant\Resources;
use App\Filament\Tenant\Resources\IncludeExcludeResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use BackedEnum;
use UnitEnum;
use App\Models\IncludeExclude;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
class IncludeExcludeResource extends Resource
{
 protected static ?string $model = IncludeExclude::class;
 protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-list-bullet';
 protected static UnitEnum|string|null $navigationGroup = 'Catalog';
 protected static ?int $navigationSort = 2;
 public static function form(Schema $schema): Schema { return $schema->components([Select::make('type')->options(['include'=>'Include','exclude'=>'Exclude'])->required(),TextInput::make('title')->required()->maxLength(255),Textarea::make('description')->rows(4),TextInput::make('sort_order')->numeric()->integer()->minValue(0)->default(0)]); }
 public static function table(Table $table): Table { return $table->columns([TextColumn::make('type')->badge(),TextColumn::make('title')->searchable()->sortable(),TextColumn::make('sort_order')->sortable()])->defaultSort('sort_order')->recordActions([EditAction::make(),DeleteAction::make()]); }
 public static function getPages(): array { return ['index'=>Pages\ListIncludeExcludes::route('/'),'create'=>Pages\CreateIncludeExclude::route('/create'),'edit'=>Pages\EditIncludeExclude::route('/{record}/edit')]; }
}


