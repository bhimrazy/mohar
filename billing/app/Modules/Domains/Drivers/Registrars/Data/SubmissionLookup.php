<?php

namespace App\Modules\Domains\Drivers\Registrars\Data;

use App\Enums\ReconcileStatus;
use Carbon\CarbonImmutable;

/**
 * What our own registrar account says about a mutating command whose outcome we lost (registrars.md C2), in the
 * kernel vocabulary shared with provisioning (ReconcileStatus):
 *  - Executed: it ran; apply it (register → active, transfer → pending_transfer, renew → new expiry);
 *  - InProgress: the registrar holds it but has not finished; follow the accepted-asynchronously path;
 *  - NotExecuted: the account shows no trace of it since the submission; staff may re-submit (never automatic);
 *  - Conflict: the name is registered, but not in our account; staff decide;
 *  - Unknown: the registrar cannot tell right now; staff review.
 */
final readonly class SubmissionLookup
{
    /**
     * @param  array<string, string|int>  $remote  registrar ids found on the way (order id, transfer id)
     */
    public function __construct(
        public ReconcileStatus $status,
        public ?CarbonImmutable $expiryDate = null,
        public array $remote = [],
        public ?string $message = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'expiry_date' => $this->expiryDate?->toDateString(), 'remote' => $this->remote, 'message' => $this->message];
    }
}
