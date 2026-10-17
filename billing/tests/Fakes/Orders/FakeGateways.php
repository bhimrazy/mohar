<?php

namespace Tests\Fakes\Orders;

use App\Contracts\Billing\GatewayDirectory;

class FakeGateways implements GatewayDirectory
{
    /** @var list<array{key: string, label: string}> */
    public array $gateways = [
        ['key' => 'bank_transfer', 'label' => 'Bank transfer'],
        ['key' => 'card', 'label' => 'Credit or debit card'],
        ['key' => 'paypal', 'label' => 'PayPal'],
    ];

    public function available(string $currency): array
    {
        return $this->gateways;
    }
}
