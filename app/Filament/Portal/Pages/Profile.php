<?php

namespace App\Filament\Portal\Pages;

use App\Enums\ContactMethod;
use App\Filament\Forms\Components\CountrySelect;
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
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
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
            Section::make('Account')
                ->schema([
                    $this->getNameFormComponent(),
                    $this->getEmailFormComponent(),
                    Actions::make([$this->requestEmailChangeAction()])
                        ->visible(fn (): bool => $this->getCustomer()->isEmailVerified()),
                    FileUpload::make('avatar')
                        ->avatar()
                        ->disk('public')
                        ->directory('customer-avatars')
                        ->visibility('public')
                        ->maxSize(2048)
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp']),
                ]),
            Section::make('Contact & travel details')
                ->schema([
                    TextInput::make('phone')->label('Mobile number')->tel()->maxLength(255)
                        ->rules(fn (): array => [
                            Rule::unique('customers', 'phone')
                                ->where('tenant_id', $this->getCustomer()->tenant_id)
                                ->ignore($this->getCustomer()->getKey()),
                        ]),
                    Toggle::make('whatsapp_same_as_mobile')->label('WhatsApp same as mobile')->live(),
                    TextInput::make('whatsapp_number')->label('WhatsApp number')->tel()->maxLength(255)
                        ->hidden(fn (Get $get): bool => (bool) $get('whatsapp_same_as_mobile')),
                    Select::make('preferred_contact_method')->options(ContactMethod::class),
                    TextInput::make('preferred_language')->maxLength(255),
                    Textarea::make('address')->rows(3),
                    DatePicker::make('date_of_birth')->maxDate(today()),
                    CountrySelect::make('nationality_id', nationality: true)->label('Nationality'),
                    CountrySelect::make('country_of_residence_id')->label('Country of residence'),
                    TextInput::make('passport_no')->label('Passport number')->maxLength(255),
                ]),
            Section::make('Emergency contact')
                ->schema([
                    TextInput::make('emergency_contact_name')->label('Name')->maxLength(255),
                    TextInput::make('emergency_contact_phone')->label('Phone')->tel()->maxLength(255),
                    TextInput::make('emergency_contact_relationship')->label('Relationship')->maxLength(255),
                ])
                ->collapsible(),
            Section::make('Travel preferences')
                ->schema([
                    TagsInput::make('travel_interests')->placeholder('e.g. trekking, wildlife, culture'),
                    TextInput::make('preferred_activity')->label('Preferred trek / activity type')->maxLength(255),
                    Textarea::make('dietary_preferences')->rows(2),
                    Textarea::make('special_requirements')->rows(2),
                ])
                ->collapsible(),
            Section::make('Password')
                ->schema([
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
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
