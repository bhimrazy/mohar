<?php

namespace App\Modules\Orders\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Orders\Actions\AddDomainToCart;
use App\Modules\Orders\Actions\AddProductToCart;
use App\Modules\Orders\Actions\ApplyPromotionCode;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Http\Requests\AddDomainRequest;
use App\Modules\Orders\Http\Requests\AddProductRequest;
use App\Modules\Orders\Http\Requests\ApplyPromotionRequest;
use App\Modules\Orders\Pricing\CartPricer;
use App\Modules\Orders\Services\CartManager;
use App\Modules\Orders\Services\CustomerResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function __construct(
        private CartManager $carts,
        private CustomerResolver $customers,
    ) {}

    public function show(Request $request, CartPricer $pricer): Response
    {
        $cart = $this->carts->find($request);
        $client = $this->customers->clientFor($request->user(), $request->session());
        $currency = $cart->currency ?? $this->carts->currencyFor($request);

        return Inertia::render('public/cart/index', [
            'cart' => $pricer->price($cart?->cartItems() ?? [], $currency, $cart?->promotion_code, $client)->toArray(),
            ...$this->carts->storeProps($request),
        ]);
    }

    public function storeProduct(AddProductRequest $request, int $product, AddProductToCart $add): RedirectResponse
    {
        $cart = $this->carts->findOrNew($request);
        $item = $add->handle($cart, $request->selection($product), $this->client($request));
        $this->carts->save($cart, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added to your cart.')]);

        return to_route('store.cart', ['added' => $item->key]);
    }

    public function updateProduct(AddProductRequest $request, string $item, AddProductToCart $add): RedirectResponse
    {
        $cart = $this->carts->find($request);
        $existing = $cart?->findItem($item);
        abort_if($cart === null || $existing === null || $existing->productId === null, 404);

        $add->handle($cart, $request->selection($existing->productId), $this->client($request), $item);
        $this->carts->save($cart, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cart item updated.')]);

        return to_route('store.cart');
    }

    public function storeDomain(AddDomainRequest $request, AddDomainToCart $add): RedirectResponse
    {
        $cart = $this->carts->findOrNew($request);
        $item = $add->handle($cart, $request->domainInput(), $this->client($request));
        $this->carts->save($cart, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':domain added to your cart.', ['domain' => $item->domain()])]);

        return to_route('store.cart');
    }

    public function destroy(Request $request, string $item): RedirectResponse
    {
        $cart = $this->carts->find($request);
        abort_if($cart === null || $cart->findItem($item) === null, 404);

        $cart->setCartItems(array_values(array_filter($cart->cartItems(), fn (CartItem $existing): bool => $existing->key !== $item)));
        $this->carts->save($cart, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed from your cart.')]);

        return to_route('store.cart');
    }

    public function applyPromotion(ApplyPromotionRequest $request, ApplyPromotionCode $apply): RedirectResponse
    {
        $cart = $this->carts->findOrNew($request);
        $apply->handle($cart, $request->string('promotion_code')->toString(), $this->client($request));
        $this->carts->save($cart, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion code :code applied.', ['code' => $cart->promotion_code])]);

        return to_route('store.cart');
    }

    public function removePromotion(Request $request): RedirectResponse
    {
        if ($cart = $this->carts->find($request)) {
            $cart->promotion_code = null;
            $this->carts->save($cart, $request);
        }

        return to_route('store.cart');
    }

    private function client(Request $request): ?Client
    {
        return $this->customers->clientFor($request->user(), $request->session());
    }
}
