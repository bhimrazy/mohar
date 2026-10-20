<?php

namespace App\Modules\Domains\Drivers\Registrars\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the registrar's own account says about a mutating command whose outcome we lost (registrars.md C2):
 *  - executed: it ran; apply it (register → active, transfer → pending_transfer, renew → new expiry);
 *  - in_progress: the registrar holds it but has not finished; follow the accepted-asynchronously path;
 *  - absent: the account shows no trace of it since the submission; staff may re-submit (never automatic);
 *  - unknown: the registrar cannot tell right now; staff review.
 */
final readonly class SubmissionLookup
{
    public const STATUSES = ['executed', 'in_progress', 'absent', 'unknown'];

    /**
     * @param  array<string, string|int>  $remote  registrar ids found on the way (order id, transfer id)
     */
    public function __construct(
        public string $status,
        public ?CarbonImmutable $expiryDate = null,
        public array $remote = [],
        public ?string $message = null,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown submission lookup status [{$status}].");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'expiry_date' => $this->expiryDate?->toDateString(), 'remote' => $this->remote, 'message' => $this->message];
    }
}
