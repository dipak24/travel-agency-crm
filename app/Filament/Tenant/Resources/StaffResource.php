<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\StaffResource\Pages;
use App\Models\TenantUser;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use UnitEnum;

class StaffResource extends Resource
{
    protected static ?string $model = TenantUser::class;

    protected static ?string $navigationLabel = 'Staff';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)
                ->helperText('Must be unique across the whole platform, not just this tenant — staff sign in from one shared login page.')
                ->rules(fn (?TenantUser $record): array => [
                    Rule::unique('tenant_users', 'email')->ignore($record?->getKey()),
                ]),
            TextInput::make('password')->password()->revealable()->dehydrated(fn (?string $state): bool => filled($state))
                ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                ->required(fn (string $operation): bool => $operation === 'create'),
            Select::make('status')->options([
                'active' => 'Active',
                'inactive' => 'Inactive',
                'suspended' => 'Suspended',
            ])->required()->default('active'),
            TextInput::make('designation')->maxLength(255),
            TextInput::make('department')->maxLength(255),
            DatePicker::make('joining_date'),
            Select::make('roles')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->disabled(fn (?TenantUser $record): bool => $record?->id === auth('tenant')->id())
                ->helperText(fn (?TenantUser $record): ?string => $record?->id === auth('tenant')->id()
                    ? 'You cannot change your own role or permissions.'
                    : null)
                ->saveRelationshipsUsing(function (TenantUser $record, array $state): void {
                    // Defense in depth: disabled() already excludes this field from
                    // dehydration, but never let a self-edit change roles even if
                    // that client-side state were somehow bypassed.
                    if ($record->id === auth('tenant')->id()) {
                        return;
                    }

                    $record->syncRoles($state);
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('designation'),
            TextColumn::make('department'),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('roles.name')->badge(),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }
}
