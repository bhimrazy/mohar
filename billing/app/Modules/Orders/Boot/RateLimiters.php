<?php

namespace App\Modules\Orders\Boot;

use App\Modules\Orders\OrdersServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One limiter per storefront action so the live price preview never eats the checkout budget.
 * Keyed by user when signed in, else by IP (sessions are free to discard, so they cannot be the key);
 * promotion attempts are strict to stop code enumeration.
 */
final class RateLimiters
{
    public function __invoke(Container $app): void
    {
        $key = fn (Request $request): string => OrdersServiceProvider::throttleKey($request);

        RateLimiter::for('orders-quote', fn (Request $request): Limit => Limit::perMinute(120)->by('quote|'.$key($request)));
        RateLimiter::for('orders-cart', fn (Request $request): Limit => Limit::perMinute(60)->by('cart|'.$key($request)));
        RateLimiter::for('orders-promo', fn (Request $request): Limit => Limit::perMinute(10)->by('promo|'.$key($request)));
        RateLimiter::for('orders-checkout', fn (Request $request): Limit => Limit::perMinute(10)->by('checkout|'.$key($request)));
    }
}
