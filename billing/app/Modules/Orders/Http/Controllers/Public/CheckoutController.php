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

    /** @var array<string, string> */
    private const COUNTRIES = [
        'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'IE' => 'Ireland', 'DE' => 'Germany',
        'FR' => 'France', 'NL' => 'Netherlands', 'ES' => 'Spain', 'IT' => 'Italy', 'SE' => 'Sweden',
        'PL' => 'Poland', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'IN' => 'India', 'SG' => 'Singapore',
        'JP' => 'Japan', 'BR' => 'Brazil', 'ZA' => 'South Africa',
    ];

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
            'gateways' => collect($gateways->forProducts($priced->productIds()))->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values(),
            'account' => $client === null ? null : ['name' => $client->display_name, 'email' => $client->email, 'currency' => $client->currency],
            'staff_without_client' => $request->user() !== null && $client === null,
            'require_tos' => (bool) $this->settings->get('orders.require_tos', true),
            'tos_url' => $this->settings->get('orders.tos_url') ?: null,
            'allow_notes' => (bool) $this->settings->get('orders.allow_checkout_notes', true),
            'idempotency_key' => $this->checkoutKey($request),
            'countries' => collect(self::COUNTRIES)->map(fn (string $label, string $code): array => ['value' => $code, 'label' => $label])->values(),
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
            return back()->withErrors(['cart' => __('Staff accounts cannot check out. Place orders for clients from the admin area.')]);
        }

        if ($cart === null || $cart->isEmpty()) {
            $existing = Order::query()->where('idempotency_key', $request->string('idempotency_key')->toString())->first();

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
        $client = $this->customers->clientFor($request->user(), $request->session());
        $ownsOrder = $request->session()->get(self::SESSION_LAST_ORDER) === $order->number
            || ($client !== null && $request->user()?->clients()->whereKey($order->client_id)->exists());
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
