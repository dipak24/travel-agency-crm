<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\RoleResource\Pages;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use App\Support\TenantContext;
use UnitEnum;
use Illuminate\Database\Eloquent\Builder;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationLabel = 'Staff roles';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            CheckboxList::make('permissions')
                ->label('Permissions')
                ->relationship(
                    'permissions',
                    'name',
                    fn (Builder $query): Builder => $query->where('guard_name', 'tenant'),
                )
                ->columns(2)
                ->searchable()
                ->bulkToggleable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('team_id')->label('Tenant')->visible(false),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('name');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('team_id', app(TenantContext::class)->id())
            ->where('guard_name', 'tenant');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
