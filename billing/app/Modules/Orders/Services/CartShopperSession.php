<?php

namespace App\Modules\Orders\Services;

use App\Contracts\Orders\ShopperSession;
use Illuminate\Http\Request;
use WeakMap;

/**
 * The shopper's cart and currency for public pages (ShopperSession), read through the cart manager so the header
 * and the store always agree. Bound per request (scoped) and memoised per Request object: the layout, the page and
 * the header ask several times while one request is handled.
 */
class CartShopperSession implements ShopperSession
{
    /** @var WeakMap<Request, array{currency?: string, cart_count?: int}> */
    private WeakMap $memo;

    public function __construct(private CartManager $carts)
    {
        $this->memo = new WeakMap;
    }

    public function currency(Request $request): string
    {
        $memo = $this->memo[$request] ?? [];

        if (! isset($memo['currency'])) {
            $memo['currency'] = $this->carts->currencyFor($request);
            $this->memo[$request] = $memo;
        }

        return $memo['currency'];
    }

    public function cartCount(Request $request): int
    {
        $memo = $this->memo[$request] ?? [];

        if (! isset($memo['cart_count'])) {
            $memo['cart_count'] = count($this->carts->find($request)->items ?? []);
            $this->memo[$request] = $memo;
        }

        return $memo['cart_count'];
    }

    public function switchableCurrencies(Request $request): array
    {
        return $this->carts->switchableCurrencies($request);
    }
}
