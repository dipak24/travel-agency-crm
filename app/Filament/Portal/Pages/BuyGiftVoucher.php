<?php

namespace App\Filament\Portal\Pages;

use App\Filament\Forms\Components\MoneyInput;
use App\Models\Invoice;
use App\Services\GiftVoucherPurchase;
use App\Services\PaymentGateways\PaymentGatewayResolver;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\HtmlString;

class BuyGiftVoucher extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Buy a Gift Voucher';

    protected static ?int $navigationSort = 50;

    /**
     * The "Custom amount" option accepts anything in this range — arbitrary but sane bounds to
     * catch fat-finger mistakes (e.g. $0.01 or $999,999) rather than a rule tied to the preset tiers.
     */
    private const MIN_CUSTOM_AMOUNT = 10;

    private const MAX_CUSTOM_AMOUNT = 5000;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $customer = auth('customer')->user();
        [$firstName, $lastName] = $this->splitName($customer->name);

        $this->form->fill([
            'amount_choice' => '100',
            'purchaser_first_name' => $firstName,
            'purchaser_last_name' => $lastName,
            'purchaser_email' => $customer->email,
            'purchaser_phone' => $customer->phone,
            'send_to_self' => false,
            // fill() with an explicit array doesn't backfill per-component defaults for keys left
            // out of it (unlike a bare fill() call) — the Repeater's own defaultItems(1) never ran,
            // so this must be spelled out here or the page loads with zero recipient rows and the
            // Payment summary's total shows $0.00 until the customer manually adds one.
            'recipients' => [
                ['first_name' => null, 'last_name' => null, 'email' => null, 'phone' => null],
            ],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Choose an amount')
                    ->schema([
                        ToggleButtons::make('amount_choice')
                            ->hiddenLabel()
                            ->options([
                                '100' => '$100',
                                '200' => '$200',
                                '300' => '$300',
                                '400' => '$400',
                                '500' => '$500',
                                'custom' => 'Custom amount',
                            ])
                            ->inline()
                            ->required()
                            ->live()
                            ->default('100'),
                        MoneyInput::make('custom_amount')
                            ->label('Enter amount')
                            ->minValue(self::MIN_CUSTOM_AMOUNT)
                            ->maxValue(self::MAX_CUSTOM_AMOUNT)
                            ->live(onBlur: true)
                            ->required(fn (Get $get): bool => $get('amount_choice') === 'custom')
                            ->visible(fn (Get $get): bool => $get('amount_choice') === 'custom')
                            ->helperText(fn (): string => 'Enter an amount between '
                                .Money::format(self::MIN_CUSTOM_AMOUNT * 100, $this->tenantCurrency()).' and '
                                .Money::format(self::MAX_CUSTOM_AMOUNT * 100, $this->tenantCurrency()).'.'),
                    ]),
                Section::make('Purchase info')
                    ->description('Confirm who is buying this gift — these details are only used for this order.')
                    ->schema([
                        TextInput::make('purchaser_first_name')->label('First name')->required()->maxLength(255),
                        TextInput::make('purchaser_last_name')->label('Last name')->required()->maxLength(255),
                        TextInput::make('purchaser_email')->label('Email')->email()->required()->maxLength(255),
                        TextInput::make('purchaser_phone')->label('Phone')->tel()->required()->maxLength(30),
                    ])
                    ->columns(2),
                Section::make('Recipients')
                    ->description('Who should receive the voucher? Send the same amount to more than one person if you like — each gets their own voucher code.')
                    ->schema([
                        Checkbox::make('send_to_self')
                            ->label('Send to myself')
                            ->helperText('Uses your purchase info above as the recipient.')
                            ->live()
                            ->columnSpanFull(),
                        Repeater::make('recipients')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('first_name')->label('First name')->required()->maxLength(255),
                                TextInput::make('last_name')->label('Last name')->required()->maxLength(255),
                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->rules([$this->uniqueWithinRecipients('email', 'This email is already used for another recipient in this order.')]),
                                TextInput::make('phone')
                                    ->label('Mobile')
                                    ->tel()
                                    ->required()
                                    ->maxLength(30)
                                    ->rules([$this->uniqueWithinRecipients('phone', 'This mobile number is already used for another recipient in this order.')]),
                            ])
                            ->columns(2)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->live()
                            ->addActionLabel('Add another recipient')
                            ->addActionAlignment(Alignment::Start)
                            ->itemLabel(fn (array $state): ?string => trim(($state['first_name'] ?? '').' '.($state['last_name'] ?? '')) ?: 'Recipient')
                            ->required(fn (Get $get): bool => ! $get('send_to_self'))
                            ->visible(fn (Get $get): bool => ! $get('send_to_self')),
                    ])
                    ->columns(1),
                Section::make('Payment summary')
                    ->schema([
                        Placeholder::make('summary_amount')
                            ->label('Amount per voucher')
                            ->content(fn (Get $get): string => Money::format($this->amountMinorFromState($get('amount_choice'), $get('custom_amount')), $this->tenantCurrency())),
                        Placeholder::make('summary_recipients')
                            ->label('Recipients')
                            ->content(fn (Get $get): string => (string) $this->recipientCountFromState((bool) $get('send_to_self'), $get('recipients'))),
                        Placeholder::make('summary_subtotal')
                            ->label('Subtotal')
                            ->content(fn (Get $get): string => Money::format($this->totalMinorFromState($get), $this->tenantCurrency())),
                        Placeholder::make('summary_total')
                            ->label('Total')
                            ->content(fn (Get $get): string => Money::format($this->totalMinorFromState($get), $this->tenantCurrency()))
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                    ])
                    ->columns(2),
                Section::make('Payment')
                    ->schema([
                        ToggleButtons::make('gateway')
                            ->hiddenLabel()
                            ->options(fn (): array => $this->enabledGatewayOptions())
                            ->icons($this->gatewayIcons())
                            ->inline()
                            ->required()
                            ->helperText(fn (): ?string => $this->enabledGatewayOptions() === []
                                ? 'No payment method is currently available — please contact us.'
                                : null),
                    ]),
            ])
            ->statePath('data')
            ->columns(1);
    }

    public function buy(): ?RedirectResponse
    {
        $data = $this->form->getState();
        $customer = auth('customer')->user();

        $amountMinor = $this->amountMinorFromState($data['amount_choice'], $data['custom_amount'] ?? null);

        $purchaser = [
            'first_name' => $data['purchaser_first_name'],
            'last_name' => $data['purchaser_last_name'],
            'email' => $data['purchaser_email'],
            'phone' => $data['purchaser_phone'],
        ];

        $recipients = $data['send_to_self']
            ? [$purchaser]
            : collect($data['recipients'])
                ->map(fn (array $recipient): array => [
                    'first_name' => $recipient['first_name'],
                    'last_name' => $recipient['last_name'],
                    'email' => $recipient['email'],
                    'phone' => $recipient['phone'],
                ])
                ->values()
                ->all();

        $invoice = app(GiftVoucherPurchase::class)->createInvoice($customer, $purchaser, $amountMinor, $this->tenantCurrency(), $recipients);

        return redirect()->to(route('payments.checkout', ['gateway' => $data['gateway'], 'invoice' => $invoice->id]));
    }

    /**
     * A closure-based validation rule confirming no two recipients in this order share the same
     * value for $field (email/phone) — scoped to just this purchase, not a database-wide check.
     */
    private function uniqueWithinRecipients(string $field, string $message): Closure
    {
        return function (Get $get) use ($field, $message): Closure {
            return function (string $attribute, mixed $value, Closure $fail) use ($get, $field, $message): void {
                $value = mb_strtolower(trim((string) $value));

                if ($value === '') {
                    return;
                }

                $matches = collect($get('../'))
                    ->pluck($field)
                    ->filter()
                    ->map(fn ($sibling): string => mb_strtolower(trim((string) $sibling)))
                    ->filter(fn (string $sibling): bool => $sibling === $value)
                    ->count();

                if ($matches > 1) {
                    $fail($message);
                }
            };
        };
    }

    /**
     * @return array<string, string>
     */
    private function enabledGatewayOptions(): array
    {
        $customer = auth('customer')->user();
        $transientInvoice = new Invoice(['tenant_id' => $customer->tenant_id, 'currency' => $this->tenantCurrency()]);

        return collect(app(PaymentGatewayResolver::class)->enabledFor($transientInvoice))
            ->mapWithKeys(fn ($gateway): array => [$gateway->key() => $gateway->label()])
            ->all();
    }

    /**
     * Small brand-styled badges shown on each "Pay via" card instead of a generic icon — keyed by
     * gateway key so an option that isn't currently enabled (and so never appears in
     * enabledGatewayOptions()) simply never gets looked up here.
     *
     * @return array<string, string|Htmlable>
     */
    private function gatewayIcons(): array
    {
        return [
            'paypal' => new HtmlString(
                '<span class="inline-flex items-center rounded bg-[#003087] px-2 py-0.5 text-[11px] font-extrabold italic leading-loose text-white">'
                .'Pay<span class="text-[#00c1f2]">Pal</span></span>'
            ),
            'hbl' => new HtmlString(
                '<span class="inline-flex items-center rounded bg-[#00693e] px-2 py-0.5 text-[11px] font-extrabold leading-loose tracking-wide text-white">HBL</span>'
            ),
            'pay_later' => 'heroicon-o-clock',
        ];
    }

    private function tenantCurrency(): string
    {
        return auth('customer')->user()->tenant?->currency ?? 'USD';
    }

    private function amountMinorFromState(?string $amountChoice, mixed $customAmount): int
    {
        if ($amountChoice === 'custom') {
            return (int) (Money::toCents($customAmount) ?? 0);
        }

        return ((int) $amountChoice) * 100;
    }

    private function recipientCountFromState(bool $sendToSelf, mixed $recipients): int
    {
        if ($sendToSelf) {
            return 1;
        }

        return is_array($recipients) ? count($recipients) : 0;
    }

    private function totalMinorFromState(Get $get): int
    {
        return $this->amountMinorFromState($get('amount_choice'), $get('custom_amount'))
            * $this->recipientCountFromState((bool) $get('send_to_self'), $get('recipients'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name): array
    {
        $parts = explode(' ', trim((string) $name), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
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
