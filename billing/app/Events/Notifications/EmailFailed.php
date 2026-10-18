<?php

namespace App\Events\Notifications;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when an email failed for good (all transport attempts used, or the template did not render).
 */
final class EmailFailed
{
    use Dispatchable, IdentifiesOccurrence;

    public function __construct(
        public int $emailMessageId,
        public ?string $templateKey,
        public ?int $clientId,
    ) {}
}
