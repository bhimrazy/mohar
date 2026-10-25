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
     * A copy serialised before a trailing defaulted constructor parameter was added has no value for it: it gets the
     * default, as a new event would (ADR 0010 Amendment (#482)).
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {
            $this->{$property} = $value;
        }

        foreach (self::promotedDefaults() as $property => $default) {
            if (! array_key_exists($property, $data)) {
                $this->{$property} = $default;
            }
        }
    }

    /**
     * Promoted constructor parameters that have a default, per class.
     *
     * @return array<string, mixed>
     */
    private static function promotedDefaults(): array
    {
        static $defaults = [];

        return $defaults[static::class] ??= collect((new \ReflectionClass(static::class))->getConstructor()?->getParameters() ?? [])
            ->filter(fn (\ReflectionParameter $parameter): bool => $parameter->isPromoted() && $parameter->isDefaultValueAvailable())
            ->mapWithKeys(fn (\ReflectionParameter $parameter): array => [$parameter->getName() => $parameter->getDefaultValue()])
            ->all();
    }
}
