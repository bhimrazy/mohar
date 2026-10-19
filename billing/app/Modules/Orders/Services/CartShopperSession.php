<?php

namespace App\Modules\Orders\Services;

use App\Contracts\Orders\ShopperSession;
use App\Modules\Core\Models\Currency;
use Illuminate\Http\Request;

/**
 * The shopper's cart and currency for public pages (ShopperSession), read through the cart manager so the header
 * and the store always agree.
 */
class CartShopperSession implements ShopperSession
{
    public function __construct(private CartManager $carts) {}

    public function currency(Request $request): string
    {
        return $this->carts->currencyFor($request);
    }

    public function cartCount(Request $request): int
    {
        return count($this->carts->find($request)->items ?? []);
    }

    public function switchableCurrencies(Request $request): array
    {
        // Guests pick a currency; a signed-in client shops in the account's currency (same rule as storeProps()).
        if ($request->user() !== null) {
            return [];
        }

        return array_values(Currency::query()->orderByDesc('is_default')->orderBy('code')->pluck('code')->all());
    }
}
