<?php

namespace App\Contracts\Currencies;

use App\Data\Currencies\CurrencyDisplay;

/**
 * Read access to the configured currencies' display settings. Core binds a cached implementation that is
 * flushed whenever a currency is saved or deleted.
 */
interface CurrencyDirectory
{
    public function display(string $code): ?CurrencyDisplay;

    /**
     * Every configured currency, default first, then by code.
     *
     * @return list<CurrencyDisplay>
     */
    public function displays(): array;
}
