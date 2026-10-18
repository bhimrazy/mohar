<?php

namespace App\Modules\Orders\Fraud;

use App\Modules\Orders\Enums\FraudDecision;

/**
 * Screening disabled: every order passes.
 */
class NoneFraudChecker implements FraudChecker
{
    public function name(): string
    {
        return 'none';
    }

    public function check(FraudContext $context): FraudResult
    {
        return new FraudResult(FraudDecision::Pass);
    }
}
