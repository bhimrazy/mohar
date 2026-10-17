<?php

namespace App\Contracts\Billing;

use App\Support\Money;

/**
 * Implemented by the Billing module. Client credit balance per currency.
 */
interface CreditLedger
{
    public function balance(int $clientId, string $currency): Money;

    public function credit(int $clientId, Money $amount, string $description): void;

    /**
     * @throws \DomainException when the balance is insufficient
     */
    public function debit(int $clientId, Money $amount, string $description): void;
}
