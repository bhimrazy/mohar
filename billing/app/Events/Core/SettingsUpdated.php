<?php

namespace App\Events\Core;

use App\Support\Events\IdentifiesOccurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Settings were saved; keys lists the changed setting keys (values are not included).
 */
final class SettingsUpdated
{
    use Dispatchable, IdentifiesOccurrence;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public array $keys,
    ) {}
}
