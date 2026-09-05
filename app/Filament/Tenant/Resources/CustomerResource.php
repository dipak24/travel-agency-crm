<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\CustomerResource\Pages;
use App\Models\Customer;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...static::quickCreateSchema(),
            TextInput::make('nationality')->maxLength(255),
            TextInput::make('password')->password()->revealable()->label('Portal password'),
            Textarea::make('address')->rows(3),
            Textarea::make('notes')->rows(4),
        ]);
    }

    /**
     * Minimal customer fields for creating a walk-in/guest customer inline
     * from another resource's form (e.g. a Select's createOptionForm on
     * BookingResource/InvoiceResource), without leaving the page.
     *
     * @return array<int, Component>
     */
    public static function quickCreateSchema(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)
                ->rules(fn (?Customer $record): array => [
                    Rule::unique('customers', 'email')
                        ->where('tenant_id', app(TenantContext::class)->id())
                        ->ignore($record?->getKey()),
                ]),
            TextInput::make('phone')->maxLength(255)
                ->rules(fn (?Customer $record): array => [
                    Rule::unique('customers', 'phone')
                        ->where('tenant_id', app(TenantContext::class)->id())
                        ->ignore($record?->getKey()),
                ]),
            Select::make('type')->options([
                'individual' => 'Individual',
                'agency' => 'Agency',
                'group_leader' => 'Group leader',
            ])->required()->default('individual'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('phone'),
            TextColumn::make('type')->badge(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
