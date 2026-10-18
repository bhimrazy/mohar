<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when the transport accepted an email.
 */
final class EmailSent
{
    use Dispatchable;

    public function __construct(
        public int $emailMessageId,
        public ?string $templateKey,
        public ?int $clientId,
    ) {}
}
