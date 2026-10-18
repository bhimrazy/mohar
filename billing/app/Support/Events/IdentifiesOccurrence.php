<?php

namespace App\Support\Events;

use Illuminate\Support\Str;

/**
 * Gives a domain event a unique id per occurrence (not per payload). Listeners that must be idempotent —
 * email dedupe, webhook event ids — key on it: re-handling the same event instance (a retried listener,
 * a re-dispatched or unserialised copy) is recognised, while two separate events with identical payloads
 * are two occurrences. The id is assigned on first read and travels with the object when serialised.
 */
trait IdentifiesOccurrence
{
    public string $occurrenceId = '';

    public function occurrenceId(): string
    {
        if ($this->occurrenceId === '') {
            $this->occurrenceId = (string) Str::uuid();
        }

        return $this->occurrenceId;
    }
}
