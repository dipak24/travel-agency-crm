<?php

namespace App\Filament\Portal\Pages;

use App\Models\Invoice;
use App\Services\GiftVoucherPurchase;
use App\Services\PaymentGateways\PaymentGatewayResolver;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Http\RedirectResponse;

class BuyGiftVoucher extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Buy a Gift Voucher';

    protected static ?int $navigationSort = 50;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText(fn (): string => 'Enter the amount in normal currency units (e.g. 50.00), not cents. Currency: '.$this->tenantCurrency()),
                Select::make('gateway')
                    ->label('Pay via')
                    ->options(fn (): array => $this->enabledGatewayOptions())
                    ->required()
                    ->helperText(fn (): ?string => $this->enabledGatewayOptions() === []
                        ? 'No payment method is currently available — please contact us.'
                        : null),
            ])
            ->statePath('data')
            ->columns(1);
    }

    public function buy(): ?RedirectResponse
    {
        $data = $this->form->getState();
        $customer = auth('customer')->user();
        $amountMinor = (int) Money::toCents($data['amount']);

        $invoice = app(GiftVoucherPurchase::class)->createInvoice($customer, $amountMinor, $this->tenantCurrency());

        return redirect()->to(route('payments.checkout', ['gateway' => $data['gateway'], 'invoice' => $invoice->id]));
    }

    /**
     * @return array<string, string>
     */
    private function enabledGatewayOptions(): array
    {
        $customer = auth('customer')->user();
        $transientInvoice = new Invoice(['tenant_id' => $customer->tenant_id, 'currency' => $this->tenantCurrency()]);

        return collect(app(PaymentGatewayResolver::class)->enabledFor($transientInvoice))
            ->mapWithKeys(fn ($gateway): array => [$gateway->key() => strtoupper($gateway->key())])
            ->all();
    }

    private function tenantCurrency(): string
    {
        return auth('customer')->user()->tenant?->currency ?? 'USD';
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('buy')->label('Buy voucher')->submit('buy'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('buy')
                ->footer([
                    Actions::make($this->getFormActions())->key('form-actions'),
                ]),
        ]);
    }
}
