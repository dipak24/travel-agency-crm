<?php

namespace App\Filament\Tenant\Resources;

use App\Enums\ContactMethod;
use App\Enums\CustomerSpecialDateType;
use App\Enums\CustomerStatus;
use App\Filament\Concerns\SendsAccountLinks;
use App\Filament\Forms\Components\CountrySelect;
use App\Filament\Tenant\Resources\CustomerResource\Pages;
use App\Models\Customer;
use App\Services\Auth\AccountSetupLinks;
use App\Services\Auth\CustomerEmailChange;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use UnitEnum;

class CustomerResource extends Resource
{
    use SendsAccountLinks;

    protected static ?string $model = Customer::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 1;

    /**
     * Staff never set a customer's password here — customers choose their own through an emailed
     * setup/reset link (see accountActions()). A verified email is read-only too: changing it goes
     * through the "Change email" action, which only applies once the new address is confirmed.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Account')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        static::emailField()
                            ->disabled(fn (?Customer $record): bool => $record?->isEmailVerified() ?? false)
                            ->helperText(fn (?Customer $record): ?string => $record?->isEmailVerified()
                                ? 'Verified emails can only be changed with the "Change email" action, which asks the customer to confirm the new address.'
                                : null),
                        static::typeField(),
                    ])
                    ->columns(3),
                Section::make('Contact')
                    ->columnSpanFull()
                    ->schema([
                        static::mobileField()->required(),
                        Toggle::make('whatsapp_same_as_mobile')->label('WhatsApp same as mobile')->live()->inline(false),
                        TextInput::make('whatsapp_number')->label('WhatsApp number')->tel()->maxLength(255)
                            ->hidden(fn (Get $get): bool => (bool) $get('whatsapp_same_as_mobile')),
                        Select::make('preferred_contact_method')->options(ContactMethod::class),
                        TextInput::make('preferred_language')->maxLength(255),
                        Textarea::make('address')->rows(2)->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Personal details')
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('date_of_birth')->required()->maxDate(today()),
                        CountrySelect::make('nationality_id', nationality: true)->label('Nationality')->required(),
                        CountrySelect::make('country_of_residence_id')->label('Country of residence'),
                    ])
                    ->columns(3),
                Section::make('Emergency contact')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('emergency_contact_name')->label('Name')->maxLength(255),
                        TextInput::make('emergency_contact_phone')->label('Phone')->tel()->maxLength(255),
                        TextInput::make('emergency_contact_relationship')->label('Relationship')->maxLength(255),
                    ])
                    ->columns(3)
                    ->collapsible(),
                Section::make('Travel preferences')
                    ->columnSpanFull()
                    ->schema([
                        TagsInput::make('travel_interests')->placeholder('e.g. trekking, wildlife, culture'),
                        TextInput::make('preferred_activity')->label('Preferred trek / activity type')->maxLength(255),
                        Textarea::make('dietary_preferences')->rows(2),
                        Textarea::make('special_requirements')->rows(2),
                        Textarea::make('notes')->rows(3)->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),
                Section::make('Special dates')
                    ->description('Anniversaries and other occasions to remember. The birthday comes from the date of birth above.')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('specialDates')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                Select::make('type')->options(CustomerSpecialDateType::class)->required()->live(),
                                TextInput::make('label')->maxLength(255)
                                    ->required(fn (Get $get): bool => $get('type') === CustomerSpecialDateType::Other->value
                                        || $get('type') === CustomerSpecialDateType::Other),
                                DatePicker::make('date')->required(),
                                Toggle::make('remind')->label('Remind staff')->live()->inline(false),
                                TextInput::make('remind_days_before')->label('Days before')->numeric()->minValue(0)->maxValue(365)
                                    ->visible(fn (Get $get): bool => (bool) $get('remind')),
                            ])
                            ->columns(5)
                            ->defaultItems(0)
                            ->addActionLabel('Add special date'),
                    ])
                    ->collapsible(),
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
            static::emailField(),
            static::mobileField(),
            static::typeField(),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Profile')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('type')->badge()->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()),
                        TextEntry::make('date_of_birth')->label('Date of birth')->date('M j, Y')->placeholder('—'),
                        TextEntry::make('nationality.nationality')->label('Nationality')->placeholder('—'),
                        TextEntry::make('countryOfResidence.name')->label('Country of residence')->placeholder('—'),
                        TextEntry::make('preferred_language')->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('Account')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('email_verified_at')->label('Email')
                            ->state(fn (Customer $record): string => $record->isEmailVerified() ? 'Verified' : 'Pending verification')
                            ->badge()
                            ->color(fn (Customer $record): string => $record->isEmailVerified() ? 'success' : 'warning')
                            ->helperText(fn (Customer $record): ?string => $record->email_verified_at?->format('M j, Y g:i A')),
                        TextEntry::make('pending_email')->label('Pending email change')
                            ->visible(fn (Customer $record): bool => filled($record->pending_email))
                            ->helperText('Waiting for the customer to confirm this address.'),
                        IconEntry::make('password')->label('Portal password set')
                            ->state(fn (Customer $record): bool => $record->password !== null)
                            ->boolean(),
                        TextEntry::make('last_login_at')->label('Last login')->dateTime('M j, Y g:i A')->placeholder('Never logged in'),
                        TextEntry::make('created_at')->label('Account created')->dateTime('M j, Y g:i A'),
                    ]),
                Section::make('Contact')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('phone')->label('Mobile')->copyable()->placeholder('—'),
                        TextEntry::make('whatsapp_number')->label('WhatsApp')->placeholder('—'),
                        TextEntry::make('preferred_contact_method')->label('Preferred contact')->placeholder('—'),
                        TextEntry::make('address')->placeholder('—')->columnSpan(2),
                        TextEntry::make('emergency_contact_name')->label('Emergency contact')->placeholder('—')
                            ->helperText(fn (Customer $record): ?string => collect([$record->emergency_contact_relationship, $record->emergency_contact_phone])->filter()->implode(' · ') ?: null),
                    ])
                    ->columns(3),
                Section::make('Travel preferences')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('travel_interests')->badge()->placeholder('—'),
                        TextEntry::make('preferred_activity')->label('Preferred trek / activity')->placeholder('—'),
                        TextEntry::make('dietary_preferences')->placeholder('—'),
                        TextEntry::make('special_requirements')->placeholder('—'),
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Special dates')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('specialDates')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('type')->badge(),
                                TextEntry::make('label')->placeholder('—'),
                                TextEntry::make('date')->date('M j, Y'),
                                TextEntry::make('remind_days_before')->label('Reminder')
                                    ->state(fn ($record): string => $record->remind ? ($record->remind_days_before ?? 0).' day(s) before' : 'Off'),
                            ])
                            ->columns(4)
                            ->contained(false),
                    ])
                    ->visible(fn (Customer $record): bool => $record->specialDates->isNotEmpty()),
                Section::make('Relationship summary')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('leads_count')->label('Leads')->state(fn (Customer $record): int => $record->leads()->count()),
                        TextEntry::make('bookings_count')->label('Bookings')->state(fn (Customer $record): int => $record->bookings()->count()),
                        TextEntry::make('invoices_count')->label('Invoices')->state(fn (Customer $record): int => $record->invoices()->count()),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->weight('bold')
                ->description(fn (Customer $record): string => $record->email.' · '.($record->last_login_at
                    ? 'Last login: '.$record->last_login_at->format('M j, Y g:i A')
                    : 'Never logged in')),
            TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('phone')->label('Mobile')->searchable()->placeholder('—'),
            TextColumn::make('status')->badge()->sortable(),
            IconColumn::make('email_verified_at')->label('Email verified')
                ->state(fn (Customer $record): bool => $record->isEmailVerified())
                ->boolean()
                ->tooltip(fn (Customer $record): string => $record->isEmailVerified() ? 'Verified' : 'Pending verification'),
            TextColumn::make('nationality.nationality')->label('Nationality')->placeholder('—')->toggleable(),
            TextColumn::make('type')->badge()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('last_login_at')->label('Last login')->dateTime('M j, Y g:i A')->sortable()
                ->placeholder('Never')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('nationality'))
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')->options(CustomerStatus::class),
                TernaryFilter::make('email_verified')
                    ->label('Email verification')
                    ->trueLabel('Verified')
                    ->falseLabel('Pending verification')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('email_verified_at'),
                    ),
                SelectFilter::make('nationality_id')
                    ->label('Nationality')
                    ->relationship('nationality', 'nationality')
                    ->searchable()
                    ->preload(),
            ])
            ->recordUrl(fn (Customer $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ...static::accountActions(),
                    static::deleteAction(),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->size('sm')
                    ->tooltip('Actions'),
            ]);
    }

    /**
     * Account actions shared by the list's row menu and the view page's header menu.
     *
     * @return array<int, Action>
     */
    public static function accountActions(): array
    {
        return [
            Action::make('sendAccessLink')
                ->label(fn (Customer $record): string => $record->password === null ? 'Send setup link' : 'Send password reset link')
                ->icon('heroicon-o-key')
                ->visible(fn (Customer $record): bool => $record->status->allowsPortalAccess() && static::canManage($record))
                ->requiresConfirmation()
                ->modalHeading(fn (Customer $record): string => $record->password === null ? 'Send portal setup link' : 'Send password reset link')
                ->modalDescription(fn (Customer $record): string => ($record->password === null
                    ? "Email {$record->email} a secure link to verify their email and choose their own portal password."
                    : "Email {$record->email} a secure link to choose a new portal password. Their current password keeps working until they use it.")
                    .' The link expires in '.config('auth.passwords.customers.expire').' minutes and can only be used once.')
                ->action(fn (Customer $record) => static::sendAccessLink($record)),
            Action::make('changeEmail')
                ->label('Change email')
                ->icon('heroicon-o-at-symbol')
                ->visible(fn (Customer $record): bool => static::canManage($record))
                ->modalDescription(fn (Customer $record): string => $record->isEmailVerified()
                    ? 'This email is verified, so it will not change until the customer opens the confirmation link we send to the new address.'
                    : 'This email has not been verified yet, so it is updated right away and a new setup link is sent to the new address.')
                ->form([
                    TextInput::make('email')->label('New email')->email()->required()->maxLength(255)
                        ->rules(fn (Customer $record): array => [
                            Rule::unique('customers', 'email')->where('tenant_id', $record->tenant_id)->ignore($record->getKey()),
                            Rule::notIn([$record->email]),
                        ]),
                ])
                ->action(function (Customer $record, array $data): void {
                    if ($record->isEmailVerified()) {
                        static::deliverAccountLink(
                            fn (): bool => app(CustomerEmailChange::class)->request($record, $data['email']),
                            'Confirmation sent',
                            "The email will change once the customer confirms {$data['email']}.",
                        );

                        return;
                    }

                    $record->update(['email' => $data['email']]);

                    static::deliverAccountLink(
                        fn (): bool => app(AccountSetupLinks::class)->sendCustomerLink($record),
                        'Email updated',
                        'A new setup link was sent to the new address.',
                    );
                }),
            Action::make('cancelEmailChange')
                ->label('Cancel email change')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->visible(fn (Customer $record): bool => filled($record->pending_email) && static::canManage($record))
                ->requiresConfirmation()
                ->action(function (Customer $record): void {
                    app(CustomerEmailChange::class)->cancel($record);
                    Notification::make()->title('Email change cancelled')->success()->send();
                }),
            Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-o-pause-circle')
                ->color('danger')
                ->visible(fn (Customer $record): bool => $record->status !== CustomerStatus::Suspended && static::canManage($record))
                ->requiresConfirmation()
                ->modalDescription('A suspended customer cannot log in to the travel portal or reset their password until reactivated.')
                ->action(fn (Customer $record) => static::changeStatus($record, CustomerStatus::Suspended)),
            Action::make('deactivate')
                ->label('Mark inactive')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->visible(fn (Customer $record): bool => $record->status === CustomerStatus::Active && static::canManage($record))
                ->requiresConfirmation()
                ->modalDescription('An inactive customer cannot log in to the travel portal until reactivated.')
                ->action(fn (Customer $record) => static::changeStatus($record, CustomerStatus::Inactive)),
            Action::make('reactivate')
                ->label('Reactivate')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn (Customer $record): bool => ! $record->status->allowsPortalAccess() && static::canManage($record))
                ->requiresConfirmation()
                ->action(fn (Customer $record) => static::changeStatus(
                    $record,
                    $record->password === null ? CustomerStatus::Pending : CustomerStatus::Active,
                )),
        ];
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (Customer $record, DeleteAction $action): void {
                if ($record->bookings()->exists() || $record->invoices()->exists()) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot delete this customer')
                        ->body('This customer has existing bookings or invoices. Remove those first.')
                        ->send();

                    $action->cancel();
                }
            });
    }

    /**
     * Emails the customer a setup link (never set a password) or reset link, and reports the result.
     */
    public static function sendAccessLink(Customer $record): void
    {
        static::deliverAccountLink(
            fn (): bool => app(AccountSetupLinks::class)->sendCustomerLink($record),
            'Link sent',
            "A secure link was emailed to {$record->email}.",
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    private static function emailField(): TextInput
    {
        return TextInput::make('email')->email()->required()->maxLength(255)
            ->rules(fn (?Customer $record): array => [
                Rule::unique('customers', 'email')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($record?->getKey()),
            ]);
    }

    private static function mobileField(): TextInput
    {
        return TextInput::make('phone')->label('Mobile number')->tel()->maxLength(255)
            ->rules(fn (?Customer $record): array => [
                Rule::unique('customers', 'phone')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($record?->getKey()),
            ]);
    }

    private static function typeField(): Select
    {
        return Select::make('type')->options([
            'individual' => 'Individual',
            'agency' => 'Agency',
            'group_leader' => 'Group leader',
        ])->required()->default('individual');
    }

    private static function canManage(Customer $record): bool
    {
        return (bool) auth('tenant')->user()?->can('update', $record);
    }

    private static function changeStatus(Customer $record, CustomerStatus $status): void
    {
        $record->forceFill(['status' => $status])->save();

        Notification::make()->title("Customer marked {$status->getLabel()}")->success()->send();
    }
}
