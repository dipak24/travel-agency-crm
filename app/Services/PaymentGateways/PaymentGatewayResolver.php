<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use InvalidArgumentException;

class PaymentGatewayResolver
{
    /**
     * @var array<string, class-string<PaymentGateway>>
     */
    private array $gateways = [
        'paypal' => PayPalGateway::class,
        'hbl' => HblGateway::class,
    ];

    public function for(string $key): PaymentGateway
    {
        if (! isset($this->gateways[$key])) {
            throw new InvalidArgumentException("Unknown payment gateway [{$key}].");
        }

        return app($this->gateways[$key]);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->gateways);
    }

    /**
     * The subset of known gateways this invoice's tenant has enabled and fully configured —
     * what "Pay via X" buttons should actually be offered, on the portal and the public link alike.
     *
     * @return array<int, PaymentGateway>
     */
    public function enabledFor(Invoice $invoice): array
    {
        return array_values(array_filter(
            array_map(fn (string $key): PaymentGateway => $this->for($key), $this->keys()),
            fn (PaymentGateway $gateway): bool => $gateway->isEnabledFor($invoice),
        ));
    }
}
