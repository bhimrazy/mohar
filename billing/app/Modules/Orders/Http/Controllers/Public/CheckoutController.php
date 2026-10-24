<?php

namespace App\Modules\Orders\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Http\Middleware\CaptureReferralAndPromo;
use App\Modules\Orders\Http\Requests\CheckoutRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Pricing\CartPricer;
use App\Modules\Orders\Services\CartManager;
use App\Modules\Orders\Services\CheckoutGateways;
use App\Modules\Orders\Services\CustomerResolver;
use App\Modules\Orders\Services\InvoiceLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public const SESSION_KEY = 'orders.checkout_key';

    public const SESSION_LAST_ORDER = 'orders.last_order';

    public function __construct(
        private CartManager $carts,
        private CustomerResolver $customers,
        private Settings $settings,
    ) {}

    public function show(Request $request, CartPricer $pricer, CheckoutGateways $gateways): Response|RedirectResponse
    {
        $cart = $this->carts->find($request);

        if ($cart === null || $cart->isEmpty()) {
            return to_route('store.cart');
        }

        $client = $this->customers->clientFor($request->user(), $request->session());
        $priced = $pricer->price($cart->cartItems(), $cart->currency, $cart->promotion_code, $client);

        if ($request->user() === null) {
            $request->session()->put('url.intended', route('store.checkout'));
        }

        return Inertia::render('public/checkout/index', [
            'cart' => $priced->toArray(),
            'gateways' => collect($gateways->forProducts($priced->productIds(), $priced->currency))->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values(),
            'account' => $client === null ? null : ['name' => $client->display_name, 'email' => $client->email, 'currency' => $client->currency],
            'blocked_reason' => $request->user() === null || $client !== null ? null : ($request->user()->is_staff
                ? __("You're signed in with a staff account. Place orders for clients from the admin area.")
                : __('Your login cannot place orders for this account. Ask the account owner for billing access.')),
            'require_tos' => (bool) $this->settings->get('orders.require_tos', true),
            'tos_url' => $this->settings->get('orders.tos_url') ?: null,
            'allow_notes' => (bool) $this->settings->get('orders.allow_checkout_notes', true),
            'idempotency_key' => $this->checkoutKey($request),
            'default_country' => $this->settings->get('core.default_country'),
            'login_url' => route('login'),
            'register_url' => Route::has('register') ? route('register') : null,
            ...$this->carts->storeProps($request),
        ]);
    }

    public function store(CheckoutRequest $request, PlaceOrder $placeOrder): RedirectResponse
    {
        $cart = $this->carts->find($request);
        $client = $this->customers->clientFor($request->user(), $request->session());

        if ($request->user() !== null && $client === null) {
            return back()->withErrors(['cart' => $request->user()->is_staff
                ? __('Staff accounts cannot check out. Place orders for clients from the admin area.')
                : __('Your login cannot place orders for this account. Ask the account owner for billing access.')]);
        }

        if ($cart === null || $cart->isEmpty()) {
            // A replayed submit after the first one emptied the cart: only show the order to its own account.
            $existing = $client === null ? null : Order::query()
                ->where('client_id', $client->id)
                ->where('idempotency_key', $request->string('idempotency_key')->toString())
                ->first();

            return $existing ? $this->redirectAfterCheckout($request, $existing) : to_route('store.cart');
        }

        $placed = $placeOrder->handle(new CheckoutData(
            items: $cart->cartItems(),
            currency: $client->currency ?? $cart->currency,
            paymentGateway: $request->string('payment_gateway')->toString(),
            promotionCode: $cart->promotion_code,
            client: $client,
            registration: $request->registration(),
            userId: $request->user()?->id,
            notes: $this->settings->get('orders.allow_checkout_notes', true) && $request->filled('notes') ? $request->string('notes')->toString() : null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            idempotencyKey: $request->string('idempotency_key')->toString(),
            referralCode: CaptureReferralAndPromo::referralFrom($request),
            applyCredit: $request->boolean('apply_credit'),
            expectedNetTotal: $request->filled('expected_net_total') ? $request->integer('expected_net_total') : null,
        ));

        $this->carts->clear($cart, $request);

        if ($placed->registeredUser !== null) {
            Auth::login($placed->registeredUser);
            $request->session()->regenerate();
        }

        return $this->redirectAfterCheckout($request, $placed->order);
    }

    public function complete(Request $request, Order $order, InvoiceLinks $links): Response
    {
        $ownsOrder = $request->session()->get(self::SESSION_LAST_ORDER) === $order->number
            || $this->customers->canOrderFor($request->user(), $order->client_id);
        abort_unless($ownsOrder, 404);

        return Inertia::render('public/checkout/complete', [
            'order' => new OrderResource($order->load('items')),
            'invoice_url' => $links->client($order->invoice_id),
            'orders_url' => Route::has('client.orders.show') && $request->user() !== null ? route('client.orders.show', $order) : null,
            ...$this->carts->storeProps($request),
        ]);
    }

    private function redirectAfterCheckout(Request $request, Order $order): RedirectResponse
    {
        $request->session()->put(self::SESSION_LAST_ORDER, $order->number);
        $request->session()->forget(self::SESSION_KEY);

        $invoiceUrl = app(InvoiceLinks::class)->client($order->invoice_id);

        if ($this->settings->get('orders.checkout_redirect', 'invoice') !== 'complete_page' && $invoiceUrl !== null) {
            return redirect()->to($invoiceUrl);
        }

        return to_route('store.checkout.complete', $order);
    }

    private function checkoutKey(Request $request): string
    {
        $key = $request->session()->get(self::SESSION_KEY);

        if (! is_string($key)) {
            $key = (string) Str::uuid();
            $request->session()->put(self::SESSION_KEY, $key);
        }

        return $key;
    }
}
