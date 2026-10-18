<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Settings were saved; keys lists the changed setting keys (values are not included).
 */
final class SettingsUpdated
{
    use Dispatchable;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public array $keys,
    ) {}
}
