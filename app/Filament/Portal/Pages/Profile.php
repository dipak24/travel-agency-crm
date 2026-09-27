<?php

namespace App\Filament\Portal\Pages;

use App\Enums\ContactMethod;
use App\Filament\Forms\Components\CountrySelect;
use App\Filament\Portal\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\Auth\CustomerEmailChange;
use App\Services\Auth\TooManyAccountLinkRequests;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

class Profile extends EditProfile
{
    /**
     * The default `EditProfile::getEmailFormComponent()` validates uniqueness
     * against the whole `customers` table, but the real constraint is
     * per-tenant (`unique(['tenant_id', 'email'])`) — without this override a
     * customer could be blocked from using an email already taken by an
     * unrelated tenant's customer, which the database itself would allow.
     * Mirrors `CustomerResource::quickCreateSchema()`'s email field.
     *
     * Once verified, the email is read-only here: a change must go through
     * "Request email change", which only applies after the new address is confirmed.
     */
    protected function getEmailFormComponent(): Component
    {
        $customer = $this->getCustomer();

        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/edit-profile.form.email.label'))
            ->email()
            ->required()
            ->maxLength(255)
            ->live(debounce: 500)
            ->disabled($customer->isEmailVerified())
            ->helperText(fn (): ?string => $this->getCustomer()->pending_email
                ? "Waiting for you to confirm {$this->getCustomer()->pending_email} from the link we emailed there."
                : null)
            ->rules([
                Rule::unique('customers', 'email')
                    ->where('tenant_id', $customer->tenant_id)
                    ->ignore($customer->getKey()),
            ]);
    }

    /**
     * `attributesToArray()` (used to fill the form) respects `$hidden`, which
     * hides `passport_no` for JSON/array safety elsewhere in the app — merge
     * the real (decrypted-on-access) value back in so editing doesn't blank it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['passport_no'] = $this->getCustomer()->passport_no;

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Profile')
                ->vertical()
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Profile details')
                        ->icon('heroicon-o-user-circle')
                        ->schema([
                            Section::make('Profile details')
                                ->description('Your name and travel identity, as used on bookings and invoices.')
                                ->schema([
                                    FileUpload::make('avatar')
                                        ->avatar()
                                        ->disk('public')
                                        ->directory('customer-avatars')
                                        ->visibility('public')
                                        ->maxSize(2048)
                                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                        ->columnSpanFull(),
                                    $this->getNameFormComponent(),
                                    DatePicker::make('date_of_birth')->maxDate(today()),
                                    CountrySelect::make('nationality_id', nationality: true)->label('Nationality'),
                                    CountrySelect::make('country_of_residence_id')->label('Country of residence'),
                                    TextInput::make('passport_no')->label('Passport number')->maxLength(255),
                                    TextInput::make('preferred_language')->maxLength(255),
                                ])
                                ->columns(2),
                        ]),
                    Tab::make('Contact')
                        ->icon('heroicon-o-phone')
                        ->schema([
                            Section::make('Contact')
                                ->description('How we reach you about your trips.')
                                ->schema([
                                    $this->getEmailFormComponent(),
                                    TextInput::make('phone')->label('Mobile number')->tel()->maxLength(255)
                                        ->rules(fn (): array => [
                                            Rule::unique('customers', 'phone')
                                                ->where('tenant_id', $this->getCustomer()->tenant_id)
                                                ->ignore($this->getCustomer()->getKey()),
                                        ]),
                                    Actions::make([$this->requestEmailChangeAction()])
                                        ->visible(fn (): bool => $this->getCustomer()->isEmailVerified())
                                        ->columnSpanFull(),
                                    Toggle::make('whatsapp_same_as_mobile')->label('WhatsApp same as mobile')->live()->inline(false),
                                    TextInput::make('whatsapp_number')->label('WhatsApp number')->tel()->maxLength(255)
                                        ->hidden(fn (Get $get): bool => (bool) $get('whatsapp_same_as_mobile')),
                                    Select::make('preferred_contact_method')->options(ContactMethod::class),
                                    Textarea::make('address')->rows(3)->columnSpanFull(),
                                ])
                                ->columns(2),
                            Section::make('Emergency contact')
                                ->description('Who we should call if something happens during a trip.')
                                ->schema([
                                    TextInput::make('emergency_contact_name')->label('Name')->maxLength(255),
                                    TextInput::make('emergency_contact_phone')->label('Phone')->tel()->maxLength(255),
                                    TextInput::make('emergency_contact_relationship')->label('Relationship')->maxLength(255),
                                ])
                                ->columns(3),
                        ]),
                    Tab::make('Travel preferences')
                        ->icon('heroicon-o-globe-asia-australia')
                        ->schema([
                            Section::make('Travel preferences')
                                ->description('Helps us tailor trips and prepare for your departures.')
                                ->schema([
                                    TagsInput::make('travel_interests')->placeholder('e.g. trekking, wildlife, culture'),
                                    TextInput::make('preferred_activity')->label('Preferred trek / activity type')->maxLength(255),
                                    Textarea::make('dietary_preferences')->rows(3),
                                    Textarea::make('special_requirements')->rows(3),
                                ])
                                ->columns(2),
                        ]),
                    Tab::make('Booking history')
                        ->icon('heroicon-o-calendar-days')
                        ->schema([
                            Section::make('Booking history')
                                ->description('Your trips with us, most recent first.')
                                ->schema([
                                    RepeatableEntry::make('bookingHistory')
                                        ->hiddenLabel()
                                        ->state(fn (): Collection => $this->getCustomer()->bookings()
                                            ->latest('start_date')
                                            ->latest()
                                            ->get())
                                        ->table([
                                            TableColumn::make('Trip'),
                                            TableColumn::make('Starts'),
                                            TableColumn::make('Pax'),
                                            TableColumn::make('Status'),
                                        ])
                                        ->schema([
                                            TextEntry::make('trip_name')
                                                ->weight('medium')
                                                ->color('primary')
                                                ->url(fn (Booking $record): string => BookingResource::getUrl('view', ['record' => $record])),
                                            TextEntry::make('start_date')->date('M j, Y')->placeholder('—'),
                                            TextEntry::make('pax_count'),
                                            TextEntry::make('status')
                                                ->badge()
                                                ->formatStateUsing(fn (string $state): string => str($state)->title()),
                                        ])
                                        ->placeholder('You have no bookings yet.'),
                                ]),
                        ]),
                    Tab::make('Security')
                        ->icon('heroicon-o-lock-closed')
                        ->schema([
                            Section::make('Password')
                                ->description('Leave blank to keep your current password.')
                                ->schema([
                                    $this->getPasswordFormComponent(),
                                    $this->getPasswordConfirmationFormComponent(),
                                    $this->getCurrentPasswordFormComponent(),
                                ]),
                        ]),
                ]),
        ]);
    }

    public function requestEmailChangeAction(): Action
    {
        return Action::make('requestEmailChange')
            ->label('Request email change')
            ->link()
            ->modalDescription('We\'ll email a confirmation link to the new address. Your email only changes once you open it.')
            ->form([
                TextInput::make('email')->label('New email')->email()->required()->maxLength(255)
                    ->rules(fn (): array => [
                        Rule::unique('customers', 'email')
                            ->where('tenant_id', $this->getCustomer()->tenant_id)
                            ->ignore($this->getCustomer()->getKey()),
                        Rule::notIn([$this->getCustomer()->email]),
                    ]),
            ])
            ->action(function (array $data): void {
                try {
                    $sent = app(CustomerEmailChange::class)->request($this->getCustomer(), $data['email']);
                } catch (TooManyAccountLinkRequests $e) {
                    Notification::make()->title('Please wait')->body($e->getMessage())->warning()->send();

                    return;
                }

                if (! $sent) {
                    Notification::make()->title('Email failed to send')->body('Please try again later.')->danger()->send();

                    return;
                }

                Notification::make()->title('Check your new inbox')->body("Open the link we sent to {$data['email']} to confirm the change.")->success()->send();
            });
    }

    private function getCustomer(): Customer
    {
        /** @var Customer $customer */
        $customer = $this->getUser();

        return $customer;
    }
}
