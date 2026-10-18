<?php

namespace App\Events\Notifications;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when an email was logged but deliberately not sent (mail disabled, template disabled, ...).
 */
final class EmailSuppressed
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $emailMessageId,
        public ?string $templateKey,
        public ?int $clientId,
        public string $reason,
    ) {}
}
