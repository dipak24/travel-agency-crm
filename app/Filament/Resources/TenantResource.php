<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\SendsAccountLinks;
use App\Filament\Resources\TenantResource\Pages;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\Auth\AccountSetupLinks;
use App\Support\AgencySubdomain;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use UnitEnum;

class TenantResource extends Resource
{
    use SendsAccountLinks;

    protected static ?string $model = Tenant::class;

    protected static ?string $navigationLabel = 'Tenants';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static UnitEnum|string|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = -1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Platform settings')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('slug')->label('Agency subdomain')->required()->maxLength(63)
                            ->prefix(fn (): string => parse_url((string) config('app.url'), PHP_URL_SCHEME).'://')
                            ->suffix(fn (): string => '.'.config('agency.domain'))
                            ->helperText('The agency\'s own address: staff sign in at /tenant and customers at /portal on it. Lowercase letters, numbers and hyphens only. Changing it breaks links staff and customers already have.')
                            ->rules(fn (?Tenant $record): array => [
                                // A valid DNS label: what an agency subdomain has to be.
                                'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                                Rule::notIn(config('agency.reserved_subdomains')),
                                Rule::unique('tenants', 'slug')->ignore($record?->getKey()),
                            ])
                            ->validationMessages([
                                'regex' => 'Use lowercase letters, numbers and hyphens only, not starting or ending with a hyphen.',
                                'not_in' => 'This name is reserved by the platform. Choose another.',
                            ]),
                        Select::make('status')->options([
                            'trial' => 'Trial',
                            'active' => 'Active',
                            'suspended' => 'Suspended',
                        ])->required()->default('trial'),
                    ])
                    ->columns(2),
                Section::make('Business details')
                    ->columnSpanFull()
                    ->description('The tenant\'s public-facing identity and contact details.')
                    ->schema([
                        TextInput::make('name')->label('Business name')->required()->maxLength(255),
                        TextInput::make('address')->label('Business address')->maxLength(255)->columnSpanFull(),
                        TextInput::make('phone_number')->tel()->maxLength(255)
                            ->rules(fn (?Tenant $record): array => [
                                Rule::unique('tenants', 'phone_number')->ignore($record?->getKey()),
                            ]),
                        TextInput::make('mobile_number')->tel()->maxLength(255)
                            ->rules(fn (?Tenant $record): array => [
                                Rule::unique('tenants', 'mobile_number')->ignore($record?->getKey()),
                            ]),
                        Select::make('timezone')->searchable()
                            ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->required()->default('UTC'),
                    ])
                    ->columns(2),
                Section::make('Branding')
                    ->columnSpanFull()
                    ->description('How this agency\'s own address looks — its staff panel and customer portal, including their login pages: the business name above, plus the logo, favicon and colours here. Only a platform admin can set this.')
                    ->schema([
                        ColorPicker::make('primary_color')->label('Primary color')
                            ->rules(['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'])
                            ->helperText('Drives buttons, links, and active navigation.'),
                        ColorPicker::make('secondary_color')->label('Secondary color')
                            ->rules(['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'])
                            ->helperText('Available as an accent color.'),
                        // No SVG for either upload: an SVG served from the public disk can carry script.
                        FileUpload::make('logo')
                            ->image()
                            ->disk('public')
                            ->directory('tenant-logos')
                            ->visibility('public')
                            ->imagePreviewHeight('120')
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp']),
                        FileUpload::make('favicon')
                            ->label('Favicon')
                            ->helperText('The small icon in the browser tab. A square PNG or ICO, at least 32×32 pixels, max 512 KB.')
                            ->disk('public')
                            ->directory('tenant-favicons')
                            ->visibility('public')
                            ->imagePreviewHeight('64')
                            ->maxSize(512)
                            ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon']),
                    ])
                    ->columns(2),
                Section::make('Billing details')
                    ->columnSpanFull()
                    ->description('Where invoices and billing correspondence for this tenant are sent.')
                    ->schema([
                        TextInput::make('billing_email')->label('Billing email')->email()->maxLength(255)
                            ->rules(fn (?Tenant $record): array => [
                                Rule::unique('tenants', 'billing_email')->ignore($record?->getKey()),
                            ]),
                        Select::make('currency')->searchable()->options(self::currencyOptions())->required()->default('USD'),
                    ])
                    ->columns(2),
                Section::make('Owner account')
                    ->columnSpanFull()
                    ->description('The first staff account for this tenant, created with the "Tenant Owner" role. The owner is emailed a secure link to choose their own password.')
                    ->schema([
                        TextInput::make('owner_name')->label('Owner name')->required()->maxLength(255),
                        TextInput::make('owner_email')->label('Owner email')->email()->required()->maxLength(255)
                            ->rules([Rule::unique('tenant_users', 'email')]),
                    ])
                    ->columns(2)
                    ->visibleOn('create'),
                Section::make('Subscription')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('plan_id')->label('Subscription plan')
                            ->options(fn (): array => SubscriptionPlan::query()->where('is_active', true)->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->default(fn (?Tenant $record): ?int => $record?->activeSubscription?->plan_id),
                        DateTimePicker::make('trial_ends_at')->label('Trial ends at')
                            ->minDate(now())
                            ->helperText('Must be a future date and time.'),
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
                    ->description(fn (Tenant $record): string => AgencySubdomain::rootFor($record)),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('activeSubscription.plan.name')->label('Plan')->badge()->color('info')->placeholder('—'),
                TextColumn::make('activeSubscription.status')->label('Subscription')->badge()->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'past_due' => 'danger',
                        'cancelled' => 'gray',
                        default => 'warning',
                    }),
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
                    Action::make('sendStaffAccessLink')
                        ->label('Send password reset link')
                        ->icon('heroicon-o-key')
                        ->visible(fn (Tenant $record): bool => ! $record->trashed()
                            && (bool) auth('super_admin')->user()?->can('update', $record))
                        ->modalDescription('The staff member is emailed a secure, single-use link to choose their own password — a setup link if they have never set one.')
                        ->form([
                            Select::make('tenant_user_id')->label('Staff account')
                                ->options(fn (Tenant $record): array => static::staffAccounts($record)
                                    ->mapWithKeys(fn (TenantUser $user): array => [$user->id => "{$user->name} ({$user->email})"])
                                    ->all())
                                ->default(fn (Tenant $record): ?int => static::staffAccounts($record)->first()?->id)
                                ->helperText('Defaults to the owner (the tenant\'s first account).')
                                ->required(),
                        ])
                        ->action(function (Tenant $record, array $data): void {
                            $user = static::staffAccounts($record)->firstWhere('id', (int) $data['tenant_user_id']);

                            if ($user !== null) {
                                static::sendStaffAccessLink($user);
                            }
                        }),
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

    public static function sendStaffAccessLink(TenantUser $user): void
    {
        static::deliverAccountLink(
            fn (): bool => app(AccountSetupLinks::class)->sendStaffLink($user),
            'Link sent',
            "A secure link was emailed to {$user->email}.",
        );
    }

    /**
     * The tenant's active staff, oldest first (so the owner, created at onboarding, leads). Tenant
     * scope is bypassed because the admin panel has no tenant context.
     *
     * @return Collection<int, TenantUser>
     */
    private static function staffAccounts(Tenant $tenant): Collection
    {
        return TenantUser::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
    }

    public static function getRelations(): array
    {
        return [
            TenantResource\RelationManagers\InvoicesRelationManager::class,
        ];
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
