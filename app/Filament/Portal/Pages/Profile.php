<?php

namespace App\Filament\Portal\Pages;

use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
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
     */
    protected function getEmailFormComponent(): Component
    {
        $customer = $this->getUser();

        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/edit-profile.form.email.label'))
            ->email()
            ->required()
            ->maxLength(255)
            ->live(debounce: 500)
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
        $data['passport_no'] = $this->getUser()->passport_no;

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->schema([
                    $this->getNameFormComponent(),
                    $this->getEmailFormComponent(),
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
                    TextInput::make('phone')->maxLength(255)
                        ->rules(fn (): array => [
                            Rule::unique('customers', 'phone')
                                ->where('tenant_id', $this->getUser()->tenant_id)
                                ->ignore($this->getUser()->getKey()),
                        ]),
                    Textarea::make('address')->rows(3),
                    TextInput::make('nationality')->maxLength(255),
                    TextInput::make('passport_no')->label('Passport number')->maxLength(255),
                ]),
            Section::make('Password')
                ->schema([
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ]),
        ]);
    }
}
