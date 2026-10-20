<?php

namespace App\Modules\Core\Support;

use App\Contracts\Currencies\CurrencyDirectory;
use App\Contracts\Orders\ShopperSession;
use Illuminate\Http\Request;

/**
 * Default when Orders is not installed: the default currency, no cart, no switching.
 */
class NullShopperSession implements ShopperSession
{
    public function __construct(private CurrencyDirectory $currencies) {}

    public function currency(Request $request): string
    {
        return ($this->currencies->displays()[0] ?? null)->code ?? 'USD';
    }

    public function cartCount(Request $request): int
    {
        return 0;
    }

    public function switchableCurrencies(Request $request): array
    {
        return [];
    }
}
