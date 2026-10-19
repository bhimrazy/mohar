<?php

namespace App\Events\Core;

use App\Contracts\Events\DomainEvent;
use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Settings were saved; keys lists the changed setting keys (values are not included).
 */
final class SettingsUpdated implements DomainEvent
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public array $keys,
    ) {}
}
