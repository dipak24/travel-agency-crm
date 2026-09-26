<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Concerns\SendsAccountLinks;
use App\Filament\Tenant\Resources\StaffResource\Pages;
use App\Models\TenantUser;
use App\Services\Auth\AccountSetupLinks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * Staff choose their own password: a new staff member (and anyone who needs a reset) is emailed a
 * single-use setup/reset link — no one types a password on their behalf here.
 */
class StaffResource extends Resource
{
    use SendsAccountLinks;

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
        ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('sendAccessLink')
                        ->label(fn (TenantUser $record): string => $record->password === null ? 'Send setup link' : 'Send password reset link')
                        ->icon('heroicon-o-key')
                        ->visible(fn (TenantUser $record): bool => (bool) auth('tenant')->user()?->can('update', $record))
                        ->requiresConfirmation()
                        ->modalDescription(fn (TenantUser $record): string => "Email {$record->email} a secure, single-use link to choose "
                            .($record->password === null ? 'their' : 'a new').' password? It expires in '.config('auth.passwords.tenant_users.expire').' minutes.')
                        ->action(fn (TenantUser $record) => static::sendAccessLink($record)),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->size('sm')
                    ->tooltip('Actions'),
            ]);
    }

    public static function sendAccessLink(TenantUser $record): void
    {
        static::deliverAccountLink(
            fn (): bool => app(AccountSetupLinks::class)->sendStaffLink($record),
            'Link sent',
            "A secure link was emailed to {$record->email}.",
        );
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
