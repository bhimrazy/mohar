<?php

namespace App\Events\Core;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One or more exchange rates changed (staff edit, scheduled update, "Update rates now", an approved review
 * or a default-currency rebase). Modules with converted prices may regenerate them when their auto-convert setting is on.
 */
final class ExchangeRatesUpdated
{
    use Dispatchable;

    /**
     * @param  list<string>  $currencies  ISO codes whose rate changed
     * @param  string  $source  "manual" (staff edit), "automatic" (scheduled or "Update rates now"), "review"
     *                          (approved held change) or "rebase" (default currency changed)
     */
    public function __construct(
        public array $currencies,
        public string $source,
    ) {}
}
