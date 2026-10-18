<?php

namespace App\Contracts\Currencies;

use App\Data\Currencies\RateSnapshot;
use App\Support\Currencies\ExchangeRateUnavailable;

/**
 * A provider of exchange rates (a central bank feed, a keyed commercial API, ...). Staff choose a source per
 * currency in Admin → Currencies; the Platform module fetches each chosen source once per update and derives
 * every rate through the default currency. Register implementations with
 * app(ExchangeRateSources::class)->register(MySource::class).
 *
 * Implementations call remote services with Laravel's Http client and short timeouts, never on a page load,
 * and keep any credentials in secret settings (encrypted at rest, never sent to the browser).
 */
interface ExchangeRateSource
{
    /** Stable machine key stored on the currency, e.g. "ecb". At most 30 characters. */
    public function key(): string;

    /** Name shown to staff, e.g. "European Central Bank reference rates". */
    public function label(): string;

    /** Where the rates come from and when they are published, for the currency edit page. */
    public function description(): string;

    /**
     * ISO codes this source can quote (its base currency included). Used to offer the source only for
     * currencies it covers; a fetch may still omit a code on a given day.
     *
     * @return list<string>
     */
    public function currencies(): array;

    /**
     * Fetch the latest published rates.
     *
     * @throws ExchangeRateUnavailable on timeouts, HTTP errors or a response that cannot be parsed
     */
    public function fetch(): RateSnapshot;
}
