<?php

namespace App\Modules\Orders\Fraud;

use App\Modules\Orders\Enums\FraudDecision;

final readonly class FraudResult
{
    /**
     * @param  float  $score  0-100, higher is riskier
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $raw  provider response, PII-redacted
     */
    public function __construct(
        public FraudDecision $decision,
        public float $score = 0,
        public array $reasons = [],
        public array $raw = [],
    ) {}
}
