<?php

namespace App\Data\Billing;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The stored attempt a driver looks up (drivers never load models). $interactive is true when a customer's browser
 * is waiting on the lookup (the return route): drivers then make one quick try and leave retries to the poller.
 * $signedCancel is true when this lookup follows a return for this attempt that the driver verified as a signed
 * cancel (ReturnHint::$signedCancel): the driver may then answer not_found with sessionEnded.
 */
final readonly class PaymentAttemptRef
{
    public function __construct(
        public string $reference,
        public Money $amount,
        public ?string $providerRef = null,
        public ?string $providerTxnId = null,
        public ?CarbonImmutable $expiresAt = null,
        public bool $interactive = false,
        #[\SensitiveParameter] public ?string $resumeUrl = null,
        public bool $signedCancel = false,
    ) {}
}
