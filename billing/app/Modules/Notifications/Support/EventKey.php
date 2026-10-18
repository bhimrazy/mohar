<?php

namespace App\Modules\Notifications\Support;

use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use WeakMap;

/**
 * The identity of one event occurrence (spec §7, §8): email dedupe keys and webhook event ids derive
 * from it. Shared events carry their own occurrence id (App\Support\Events\IdentifiesOccurrence); any
 * other object gets one per instance. Re-handling the same occurrence is skipped; two separate events
 * with identical payloads both count.
 */
final class EventKey
{
    private const NAMESPACE = '6f0b8a52-5d0e-4c55-9a77-2b7d1b1f0e10';

    /** @var WeakMap<object, string>|null */
    private static ?WeakMap $instances = null;

    public static function of(object $event): string
    {
        if (method_exists($event, 'occurrenceId')) {
            return (string) $event->occurrenceId();
        }

        self::$instances ??= new WeakMap;

        return self::$instances[$event] ??= (string) Str::uuid();
    }

    /**
     * A UUID for webhook envelopes: the occurrence id itself, or a stable UUID derived from a composite key.
     */
    public static function uuid(string $eventKey): string
    {
        return Str::isUuid($eventKey) ? $eventKey : Uuid::uuid5(self::NAMESPACE, $eventKey)->toString();
    }
}
