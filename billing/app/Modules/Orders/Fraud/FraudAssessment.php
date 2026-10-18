<?php

namespace App\Modules\Orders\Fraud;

/**
 * A provider's verdict taken before the order transaction, recorded on the order inside it.
 */
final readonly class FraudAssessment
{
    public function __construct(
        public string $provider,
        public FraudResult $result,
        public int $durationMs,
    ) {}
}
