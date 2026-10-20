<?php

namespace App\Contracts\Orders;

use Illuminate\Http\Request;

/**
 * The visitor's shopping state for public pages (header cart badge, currency switcher, prices in the visitor's
 * currency). Implemented by Orders; Core binds a default that shops in the default currency with an empty cart.
 */
interface ShopperSession
{
    /**
     * Currency the visitor sees prices in: a signed-in client's account currency, else the guest's pick, else the
     * default currency.
     */
    public function currency(Request $request): string;

    public function cartCount(Request $request): int;

    /**
     * Currency codes the visitor may switch between, default first. Empty when the visitor cannot switch (a signed-in
     * client shops in the account's currency).
     *
     * @return list<string>
     */
    public function switchableCurrencies(Request $request): array;
}
