<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationLabel = 'Tenants';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static UnitEnum|string|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = -1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Platform settings')
                ->schema([
                    TextInput::make('slug')->required()->maxLength(255)->alphaDash()
                        ->rules(fn (?Tenant $record): array => [
                            Rule::unique('tenants', 'slug')->ignore($record?->getKey()),
                        ]),
                    Select::make('status')->options([
                        'trial' => 'Trial',
                        'active' => 'Active',
                        'suspended' => 'Suspended',
                    ])->required()->default('trial'),
                ])
                ->columns(2),
            Section::make('Business details')
                ->description('The tenant\'s public-facing identity: name, contact details, and branding.')
                ->schema([
                    TextInput::make('name')->label('Business name')->required()->maxLength(255),
                    TextInput::make('address')->label('Business address')->maxLength(255)->columnSpanFull(),
                    TextInput::make('phone_number')->tel()->maxLength(255),
                    TextInput::make('mobile_number')->tel()->maxLength(255),
                    Select::make('timezone')->searchable()
                        ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                        ->required()->default('UTC'),
                    ColorPicker::make('brand_color')->label('Brand color'),
                    FileUpload::make('logo')
                        ->image()
                        ->disk('public')
                        ->directory('tenant-logos')
                        ->visibility('public')
                        ->imagePreviewHeight('120')
                        ->maxSize(2048)
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'])
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Billing details')
                ->description('Where invoices and billing correspondence for this tenant are sent.')
                ->schema([
                    TextInput::make('billing_email')->label('Billing email')->email()->maxLength(255),
                    Select::make('currency')->searchable()->options(self::currencyOptions())->required()->default('USD'),
                ])
                ->columns(2),
            Section::make('Owner account')
                ->description('The first staff account for this tenant, created with the "Tenant Owner" role.')
                ->schema([
                    TextInput::make('owner_name')->label('Owner name')->required()->maxLength(255),
                    TextInput::make('owner_email')->label('Owner email')->email()->required()->maxLength(255),
                    TextInput::make('owner_password')->label('Owner password')->password()->revealable()->required()
                        ->rules([Password::min(8)->mixedCase()->numbers()->symbols()])
                        ->helperText('At least 8 characters, including an uppercase letter, a lowercase letter, a number, and a symbol.'),
                ])
                ->columns(2)
                ->visibleOn('create'),
            Section::make('Subscription')
                ->schema([
                    Select::make('plan_id')->label('Subscription plan')
                        ->options(fn (): array => SubscriptionPlan::query()->where('is_active', true)->pluck('name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->default(fn (?Tenant $record): ?int => $record?->activeSubscription?->plan_id),
                    DateTimePicker::make('trial_ends_at')->label('Trial ends at'),
                ])
                ->columns(2),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('activeSubscription.plan');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')->label('')->circular()->defaultImageUrl(fn (Tenant $record): string => 'https://ui-avatars.com/api/?name='.urlencode($record->name).'&background=random'),
                TextColumn::make('name')->label('Business name')->weight('bold')->searchable()->sortable()
                    ->description(fn (Tenant $record): string => $record->slug),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('activeSubscription.plan.name')->label('Plan')->badge()->color('info')->placeholder('—'),
                TextColumn::make('trial_ends_at')->label('Trial ends')->date('M j, Y')->sortable()->placeholder('—')
                    ->color(fn (?Carbon $state): ?string => $state?->isPast() ? 'danger' : null),
                TextColumn::make('billing_email')->label('Billing email')->icon('heroicon-m-envelope')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('currency')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created')->date('M j, Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->striped()
            ->filters([TrashedFilter::make()])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('suspend')
                        ->label('Suspend')
                        ->icon('heroicon-o-pause-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (Tenant $record): bool => $record->status !== 'suspended')
                        ->action(fn (Tenant $record) => $record->update(['status' => 'suspended'])),
                    Action::make('reactivate')
                        ->label('Reactivate')
                        ->icon('heroicon-o-play-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Tenant $record): bool => $record->status === 'suspended')
                        ->action(fn (Tenant $record) => $record->update(['status' => 'active'])),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->size('sm')
                    ->tooltip('Actions'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function currencyOptions(): array
    {
        return [
            'USD' => 'USD - US Dollar',
            'EUR' => 'EUR - Euro',
            'GBP' => 'GBP - British Pound',
            'AUD' => 'AUD - Australian Dollar',
            'CAD' => 'CAD - Canadian Dollar',
            'AED' => 'AED - UAE Dirham',
            'INR' => 'INR - Indian Rupee',
            'NPR' => 'NPR - Nepalese Rupee',
            'JPY' => 'JPY - Japanese Yen',
        ];
    }
}
