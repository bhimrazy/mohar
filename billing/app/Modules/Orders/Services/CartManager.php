<?php

namespace App\Modules\Orders\Services;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Http\Middleware\CaptureReferralAndPromo;
use App\Modules\Orders\Models\Cart;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Finds and persists the shopper's cart: a session-token cart for guests, one cart per client account
 * once signed in. On login the guest cart is merged into the client's cart (guest items win).
 */
class CartManager
{
    public const SESSION_TOKEN = 'orders.cart_token';

    public const SESSION_CURRENCY = 'orders.currency';

    public function __construct(
        private CustomerResolver $customers,
        private Settings $settings,
    ) {}

    /**
     * The current cart, or null when the shopper has none yet.
     */
    public function find(Request $request): ?Cart
    {
        $client = $this->customers->clientFor($request->user(), $request->session());

        if ($client !== null) {
            $cart = Cart::query()->where('client_id', $client->id)->first();

            return $cart === null ? null : $this->alignCurrency($cart, $client->currency);
        }

        $token = $request->session()->get(self::SESSION_TOKEN);

        if (! is_string($token)) {
            return null;
        }

        return Cart::query()->whereNull('client_id')->where('token', $token)->where('expires_at', '>', now())->first();
    }

    /**
     * The current cart, created (unsaved) when missing.
     */
    public function findOrNew(Request $request): Cart
    {
        if ($cart = $this->find($request)) {
            return $cart;
        }

        $client = $this->customers->clientFor($request->user(), $request->session());

        return new Cart([
            'token' => (string) Str::uuid(),
            'client_id' => $client?->id,
            'currency' => $client->currency ?? $this->guestCurrency($request->session()),
            'items' => [],
        ]);
    }

    public function save(Cart $cart, Request $request): void
    {
        $pendingPromo = $request->session()->pull(CaptureReferralAndPromo::SESSION_PROMO);

        if ($cart->promotion_code === null && is_string($pendingPromo)) {
            $cart->promotion_code = $pendingPromo;
        }

        $cart->expires_at = now()->addDays((int) $this->settings->get('orders.cart_ttl_days', 7));
        $cart->save();

        if ($cart->client_id === null) {
            $request->session()->put(self::SESSION_TOKEN, $cart->token);
        }
    }

    public function clear(Cart $cart, Request $request): void
    {
        $cart->delete();
        $request->session()->forget(self::SESSION_TOKEN);
    }

    /**
     * Currency a guest shops in: their pick from the switcher, else the default currency.
     */
    public function guestCurrency(Session $session): string
    {
        $code = $session->get(self::SESSION_CURRENCY);

        return is_string($code) && Currency::query()->where('code', $code)->exists() ? $code : Currency::default()->code;
    }

    public function currencyFor(Request $request): string
    {
        return $this->customers->clientFor($request->user(), $request->session())->currency ?? $this->guestCurrency($request->session());
    }

    /**
     * Merge the session's guest cart into the client's cart. Guest items replace client items for the same
     * domain; the guest's promotion code wins. Returns true when the guest cart's currency changed.
     */
    public function mergeGuestCart(Session $session, Client $client): bool
    {
        $token = $session->get(self::SESSION_TOKEN);
        $guest = is_string($token) ? Cart::query()->whereNull('client_id')->where('token', $token)->first() : null;

        if ($guest === null) {
            return false;
        }

        $cart = Cart::query()->firstOrNew(['client_id' => $client->id], [
            'token' => (string) Str::uuid(),
            'currency' => $client->currency,
            'items' => [],
        ]);

        $guestItems = $guest->cartItems();
        $guestDomains = array_filter(array_map(fn (CartItem $item): ?string => $item->domain(), $guestItems));
        $kept = array_values(array_filter(
            $cart->cartItems(),
            fn (CartItem $item): bool => ! in_array($item->domain(), $guestDomains, true),
        ));

        $cart->setCartItems([...$kept, ...$guestItems]);
        $cart->promotion_code = $guest->promotion_code ?? $cart->promotion_code;
        $cart->currency = $client->currency;
        $cart->expires_at = now()->addDays((int) $this->settings->get('orders.cart_ttl_days', 7));
        $cart->save();

        $guest->delete();
        $session->forget(self::SESSION_TOKEN);

        return $guest->currency !== $client->currency && $guestItems !== [];
    }

    private function alignCurrency(Cart $cart, string $currency): Cart
    {
        if ($cart->currency !== $currency) {
            $cart->currency = $currency;
            $cart->save();
        }

        return $cart;
    }

    /**
     * Props every public store page needs: the cart badge and the guest currency switcher.
     *
     * @return array{cart_count: int, currencies: list<string>, can_switch_currency: bool}
     */
    public function storeProps(Request $request): array
    {
        return [
            'cart_count' => count($this->find($request)->items ?? []),
            'currencies' => array_values(Currency::query()->orderByDesc('is_default')->orderBy('code')->pluck('code')->all()),
            'can_switch_currency' => $request->user() === null,
        ];
    }
}
