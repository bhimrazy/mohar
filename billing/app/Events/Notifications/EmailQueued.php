<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when a templated or ad-hoc email is logged and handed to the queue.
 */
final class EmailQueued
{
    use Dispatchable;

    public function __construct(
        public int $emailMessageId,
        public ?string $templateKey,
        public ?int $clientId,
    ) {}
}
