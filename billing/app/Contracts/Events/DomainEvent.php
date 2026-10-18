<?php

namespace App\Contracts\Events;

/**
 * Every kernel event in app/Events (ADR 0011). The occurrence id identifies one occurrence — not its
 * payload — so idempotent consumers (email dedupe, webhook event ids) key on it. Implemented by
 * App\Support\Events\IdentifiesOccurrence.
 */
interface DomainEvent
{
    public function occurrenceId(): string;
}
