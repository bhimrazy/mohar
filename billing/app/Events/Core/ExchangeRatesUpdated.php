<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One or more exchange rates changed (staff edit, scheduled update, "Update rates now" or an approved
 * review). Modules with converted prices may regenerate them when their auto-convert setting is on.
 */
final class ExchangeRatesUpdated
{
    use Dispatchable;

    /**
     * @param  list<string>  $currencies  ISO codes whose rate changed
     * @param  string  $source  "manual", a rate source key, or "review"
     */
    public function __construct(
        public array $currencies,
        public string $source,
    ) {}
}
