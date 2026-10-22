<?php

namespace App\Events\Kyc;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff approved or declined a client's identity document. Dispatched after the decision commits. Listeners read
 * the details through the "kyc_document" merge fields (outcome, document type, reason, link), never the number or
 * the images.
 */
final class DocumentDecided implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $clientId,
        public int $documentId,
    ) {}
}
