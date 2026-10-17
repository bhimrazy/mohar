<?php

namespace App\Modules\Orders\Enums;

enum FraudDecision: string
{
    case Pass = 'pass';
    case Review = 'review';
    case Fail = 'fail';
    case Error = 'error';
    case Skipped = 'skipped';

    /**
     * Whether items may be provisioned automatically after checkout.
     */
    public function allowsAutoProvisioning(): bool
    {
        return $this === self::Pass || $this === self::Skipped;
    }
}
