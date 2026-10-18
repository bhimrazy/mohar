<?php

namespace App\Support\Events;

use Illuminate\Support\Str;

/**
 * Gives a domain event a unique id per occurrence (not per payload). Listeners that must be idempotent —
 * email dedupe, webhook event ids — key on it: re-handling the same event instance (a retried listener,
 * a re-dispatched or unserialised copy) is recognised, while two separate events with identical payloads
 * are two occurrences. The id is assigned on first read, and at the latest when the event is serialised
 * (queued listeners, retries), so every copy of one occurrence shares it.
 */
trait IdentifiesOccurrence
{
    /**
     * UUIDv7 (time-ordered); set once, on first read or serialisation (ADR 0011). Private, so nothing outside
     * the event can change it (PHPStan rejects a lazily initialised readonly property).
     */
    private string $occurrenceId = '';

    public function occurrenceId(): string
    {
        if ($this->occurrenceId === '') {
            $this->occurrenceId = (string) Str::uuid7();
        }

        return $this->occurrenceId;
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $this->occurrenceId();

        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {
            $this->{$property} = $value;
        }
    }
}
