<?php

namespace App\Modules\Notifications\Support;

use Ramsey\Uuid\Uuid;

/**
 * A deterministic identity for a domain event, so replays of the same event (queue retries, re-dispatch)
 * map to the same email dedupe keys and the same webhook event id (spec §7, §8).
 */
final class EventKey
{
    private const NAMESPACE = '6f0b8a52-5d0e-4c55-9a77-2b7d1b1f0e10';

    public static function of(object $event): string
    {
        return sha1($event::class.'|'.json_encode(get_object_vars($event)));
    }

    public static function uuid(string $eventKey): string
    {
        return Uuid::uuid5(self::NAMESPACE, $eventKey)->toString();
    }
}
