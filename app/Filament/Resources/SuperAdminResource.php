<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SuperAdminResource\Pages;
use App\Models\SuperAdmin;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class SuperAdminResource extends Resource
{
    protected static ?string $model = SuperAdmin::class;

    protected static ?string $navigationLabel = 'Platform Admins';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-circle';

    protected static UnitEnum|string|null $navigationGroup = 'Platform';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)
                ->disabled(fn (?SuperAdmin $record): bool => static::isEditingSelf($record))
                ->rules(fn (?SuperAdmin $record): array => [
                    Rule::unique('super_admins', 'email')->ignore($record?->getKey()),
                ]),
            TextInput::make('phone')->tel()->maxLength(255)
                ->rules(fn (?SuperAdmin $record): array => [
                    Rule::unique('super_admins', 'phone')->ignore($record?->getKey()),
                ]),
            TextInput::make('password')->password()->revealable()
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->rules(fn (string $operation): array => $operation === 'create'
                    ? [Password::min(8)->mixedCase()->numbers()->symbols()]
                    : ['nullable', Password::min(8)->mixedCase()->numbers()->symbols()])
                ->helperText('At least 8 characters, including an uppercase letter, a lowercase letter, a number, and a symbol.'),
            Select::make('status')->options([
                'active' => 'Active',
                'inactive' => 'Inactive',
            ])->required()->default('active')
                ->disabled(fn (?SuperAdmin $record): bool => static::isEditingSelf($record))
                ->helperText(fn (?SuperAdmin $record): ?string => static::isEditingSelf($record)
                    ? 'Only another platform admin can change your status.'
                    : null),
            Select::make('roles')
                ->relationship(
                    'roles',
                    'name',
                    fn (Builder $query): Builder => $query->where('guard_name', 'super_admin'),
                )
                ->multiple()
                ->preload()
                ->searchable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->disabled(fn (?SuperAdmin $record): bool => static::isEditingSelf($record))
                ->helperText(fn (?SuperAdmin $record): ?string => static::isEditingSelf($record)
                    ? 'Only another platform admin can change your role.'
                    : null)
                ->saveRelationshipsUsing(function (SuperAdmin $record, array $state): void {
                    $record->syncRoles($state);
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('phone')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('roles.name')->badge(),
        ])->defaultSort('name')->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuperAdmins::route('/'),
            'create' => Pages\CreateSuperAdmin::route('/create'),
            'edit' => Pages\EditSuperAdmin::route('/{record}/edit'),
        ];
    }

    private static function isEditingSelf(?SuperAdmin $record): bool
    {
        return $record !== null && $record->is(auth('super_admin')->user());
    }
}
