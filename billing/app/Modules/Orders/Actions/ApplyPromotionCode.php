<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Pricing\CartPricer;
use App\Modules\Orders\Pricing\PricedCart;
use Illuminate\Validation\ValidationException;

/**
 * Stores a promotion code on the cart once it is valid for the cart's contents.
 */
class ApplyPromotionCode
{
    public function __construct(private CartPricer $pricer) {}

    /**
     * @throws ValidationException
     */
    public function handle(Cart $cart, string $code, ?Client $client = null): PricedCart
    {
        $priced = $this->pricer->price($cart->cartItems(), $cart->currency, $code, $client);

        if ($priced->promotionError !== null) {
            throw ValidationException::withMessages(['promotion_code' => $priced->promotionError]);
        }

        $cart->promotion_code = Promotion::normalizeCode($code);

        return $priced;
    }
}
