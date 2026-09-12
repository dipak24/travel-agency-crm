<?php

namespace App\Filament\Tenant\Pages;

use App\Models\TenantPaymentGateway;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

class PaymentGateways extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Payment Gateways';

    protected static ?int $navigationSort = 101;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $paypalData = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $hblData = [];

    public function mount(): void
    {
        $existing = TenantPaymentGateway::query()
            ->whereIn('gateway', ['paypal', 'hbl'])
            ->get()
            ->keyBy('gateway');

        $this->paypalForm->fill(array_merge(
            ['mode' => 'sandbox', 'client_id' => null, 'client_secret' => null, 'webhook_id' => null],
            $existing->get('paypal')?->credentials ?? [],
            ['enabled' => (bool) $existing->get('paypal')?->enabled],
        ));

        $this->hblForm->fill(array_merge(
            [
                'mode' => 'uat', 'office_id' => null, 'api_key' => null,
                'encryption_key_id' => null, 'merchant_signing_private_key' => null,
                'merchant_decryption_private_key' => null, 'paco_encryption_public_key' => null,
                'paco_signing_public_key' => null,
            ],
            $existing->get('hbl')?->credentials ?? [],
            ['enabled' => (bool) $existing->get('hbl')?->enabled],
        ));
    }

    public static function canAccess(): bool
    {
        $user = auth('tenant')->user();

        if (! $user) {
            return false;
        }

        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', 'manage settings')->where('guard_name', 'tenant')->exists()) {
            return $user->hasRole('Tenant Owner');
        }

        return $user->hasPermissionTo('manage settings');
    }

    public function paypalForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('PayPal')
                    ->description('Merchant credentials from your PayPal Developer app. Used for both the customer portal and public payment links.')
                    ->footer([
                        Actions::make([
                            Action::make('savePaypal')
                                ->label('Save PayPal settings')
                                ->submit('savePaypal'),
                        ])->alignEnd(),
                    ])
                    ->schema([
                        Toggle::make('enabled')->label('Enabled')->live(),
                        Select::make('mode')->label('Mode')->options([
                            'sandbox' => 'Sandbox',
                            'live' => 'Live',
                        ])->required()->default('sandbox'),
                        TextInput::make('client_id')->label('Client ID')->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) $get('enabled')),
                        TextInput::make('client_secret')->label('Client secret')->password()->revealable()->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) $get('enabled')),
                        TextInput::make('webhook_id')->label('Webhook ID')
                            ->helperText('From the webhook you configure in your PayPal app to point at this invoice\'s webhook URL.')
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ])
            ->statePath('paypalData')
            ->columns(1);
    }

    public function hblForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('HBL')
                    ->description('Merchant credentials issued by HBL for their 2C2P PACO-based gateway.')
                    ->footer([
                        Actions::make([
                            Action::make('saveHbl')
                                ->label('Save HBL settings')
                                ->submit('saveHbl'),
                        ])->alignEnd(),
                    ])
                    ->schema([
                        Toggle::make('enabled')->label('Enabled')->live(),
                        Select::make('mode')->label('Mode')->options([
                            'uat' => 'UAT (testing)',
                            'live' => 'Live',
                        ])->required()->default('uat'),
                        TextInput::make('office_id')->label('Office ID (Merchant ID)')->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) $get('enabled')),
                        TextInput::make('api_key')->label('API key')->password()->revealable()->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) $get('enabled')),
                        TextInput::make('encryption_key_id')->label('Encryption key ID (kid)')->maxLength(255)->columnSpanFull()
                            ->required(fn (Get $get): bool => (bool) $get('enabled')),
                        Textarea::make('merchant_signing_private_key')
                            ->label('Merchant signing private key')
                            ->helperText('Base64 key body only, or a full PEM block — either is accepted.')
                            ->rows(4)->columnSpanFull()
                            ->required(fn (Get $get): bool => (bool) $get('enabled')),
                        Textarea::make('merchant_decryption_private_key')
                            ->label('Merchant decryption private key')
                            ->rows(4)->columnSpanFull()
                            ->required(fn (Get $get): bool => (bool) $get('enabled')),
                        Textarea::make('paco_encryption_public_key')
                            ->label('PACO encryption public key')
                            ->rows(4)->columnSpanFull()
                            ->required(fn (Get $get): bool => (bool) $get('enabled')),
                        Textarea::make('paco_signing_public_key')
                            ->label('PACO signing public key')
                            ->rows(4)->columnSpanFull()
                            ->required(fn (Get $get): bool => (bool) $get('enabled')),
                    ])
                    ->columns(2),
            ])
            ->statePath('hblData')
            ->columns(1);
    }

    public function savePaypal(): void
    {
        $this->saveGateway('paypal', $this->paypalForm);
    }

    public function saveHbl(): void
    {
        $this->saveGateway('hbl', $this->hblForm);
    }

    /**
     * The gateway's own `required(fn (Get $get) => ...)` rules on each field already enforce, at
     * validation time, that every credential in that gateway's `requiredCredentialKeys()` is
     * filled in before the toggle can be saved as enabled — `getState()` throws a
     * `ValidationException` (surfaced as form errors) before this method body is reached if not.
     */
    private function saveGateway(string $gateway, Schema $form): void
    {
        $tenant = auth('tenant')->user()->tenant;
        $values = $form->getState();
        $enabled = (bool) ($values['enabled'] ?? false);
        unset($values['enabled']);

        TenantPaymentGateway::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'gateway' => $gateway],
            ['enabled' => $enabled, 'credentials' => $values],
        );

        Notification::make()->success()->title(strtoupper($gateway).' settings saved')->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('paypalForm')])
                ->id('paypalForm')
                ->livewireSubmitHandler('savePaypal'),
            Form::make([EmbeddedSchema::make('hblForm')])
                ->id('hblForm')
                ->livewireSubmitHandler('saveHbl'),
        ]);
    }
}
