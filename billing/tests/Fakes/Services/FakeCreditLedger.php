<?php

namespace Tests\Fakes\Services;

use App\Contracts\Billing\CreditLedger;
use App\Support\Money;
use DomainException;

class FakeCreditLedger implements CreditLedger
{
    /** @var array<string, int> "clientId:currency" => balance */
    public array $balances = [];

    /** @var list<array{client_id: int, amount: int, currency: string, description: string}> */
    public array $entries = [];

    public function balance(int $clientId, string $currency): Money
    {
        return Money::of($this->balances["{$clientId}:{$currency}"] ?? 0, $currency);
    }

    public function credit(int $clientId, Money $amount, string $description): void
    {
        $key = "{$clientId}:{$amount->currency}";
        $this->balances[$key] = ($this->balances[$key] ?? 0) + $amount->amount;
        $this->entries[] = ['client_id' => $clientId, 'amount' => $amount->amount, 'currency' => $amount->currency, 'description' => $description];
    }

    public function debit(int $clientId, Money $amount, string $description): void
    {
        if ($this->balance($clientId, $amount->currency)->amount < $amount->amount) {
            throw new DomainException('Insufficient credit.');
        }

        $this->credit($clientId, $amount->negate(), $description);
    }
}
