<?php

namespace App\Modules\Orders\Fraud;

/**
 * A fraud screening provider (driver). v1 ships "none" and "rules"; third-party providers implement
 * this and are selected with the orders.fraud.driver setting.
 */
interface FraudChecker
{
    public function name(): string;

    public function check(FraudContext $context): FraudResult;
}
