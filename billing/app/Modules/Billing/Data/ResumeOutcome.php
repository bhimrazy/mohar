<?php

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Enums\AttemptStatus;

/**
 * What "Continue payment" ended in: the provider page to redirect to, or the attempt's status after one lookup and,
 * while it is still open, the message telling the customer what happens next.
 */
final readonly class ResumeOutcome
{
    public function __construct(
        public AttemptStatus $status,
        #[\SensitiveParameter] public ?string $url = null,
        public ?string $error = null,
    ) {}
}
