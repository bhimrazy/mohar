<?php

namespace App\Modules\Orders\Http\Controllers\Public;

use App\Contracts\Catalog\PriceBook;
use App\Contracts\Domains\DomainPricing;
use App\Data\Catalog\ProductSelection;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Pricing\CartPricer;
use App\Modules\Orders\Services\CartManager;
use App\Modules\Orders\Services\CustomerResolver;
use App\Modules\Orders\Services\DomainGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    private const SUGGESTED_TLDS = ['.com', '.net', '.org', '.io', '.dev'];

    public function __construct(
        private PriceBook $priceBook,
        private CartManager $carts,
    ) {}

    public function index(Request $request): Response
    {
        $currency = $this->carts->currencyFor($request);

        return Inertia::render('public/store/index', [
            'groups' => $this->priceBook->storefront($currency),
            'currency' => $currency,
            'existingDomain' => $this->existingDomain($request),
            ...$this->carts->storeProps($request),
        ]);
    }

    /**
     * A domain the visitor already owns, passed on from the domain search ("I already have it", e.g. a .np name
     * registered at the registry), to preselect "Use a domain I own".
     */
    private function existingDomain(Request $request): ?string
    {
        $domain = strtolower(trim($request->string('domain')->toString()));

        return DomainGuard::isValidName($domain) && DomainGuard::registryLevelError($domain) === null ? $domain : null;
    }

    public function show(Request $request, int $product, CustomerResolver $customers): Response
    {
        $currency = $this->carts->currencyFor($request);
        $summary = $this->priceBook->product($product);
        abort_if($summary === null || ! $summary->active, 404);

        $cycles = [];
        $clientId = $customers->clientFor($request->user(), $request->session())?->id;

        foreach ($this->priceBook->availableCycles($product, $currency) as $cycle) {
            $quote = $this->priceBook->quote(new ProductSelection($product, $cycle, $currency, clientId: $clientId));

            if ($quote !== null) {
                $cycles[] = ['cycle' => $cycle->value, 'recurring' => $quote->recurring, 'setup' => $quote->setup];
            }
        }

        $editing = $request->filled('item') ? $this->carts->find($request)?->findItem($request->string('item')->toString()) : null;

        return Inertia::render('public/store/configure', [
            'product' => [
                'id' => $summary->id,
                'name' => $summary->name,
                'group' => $summary->groupName,
                'requires_domain' => $summary->requiresDomain,
                'in_stock' => $summary->stock === null || $summary->stock > 0,
            ],
            'currency' => $currency,
            'cycles' => $cycles,
            'config_options' => $this->priceBook->configOptionsFor($product, $currency),
            'addons' => $this->priceBook->addonsFor($product, $currency),
            'custom_fields' => $this->priceBook->customFieldsFor($product),
            'editing' => $editing?->productId === $product ? [
                'key' => $editing->key,
                'cycle' => $editing->cycle?->value,
                'config_options' => $editing->configOptions,
                'custom_fields' => $editing->customFields,
                'domain_mode' => $editing->domainMode,
                'domain_name' => $editing->domain(),
                'addon_ids' => $editing->addonIds,
            ] : null,
            'signed_in' => $clientId !== null,
            'existing_domain' => $this->existingDomain($request),
            ...$this->carts->storeProps($request),
        ]);
    }

    /**
     * Live price preview for the configure page (excl. tax), priced by the server.
     */
    public function quote(Request $request, int $product, CartPricer $pricer, CustomerResolver $customers): JsonResponse
    {
        $request->validate([
            'cycle' => ['required', 'string', 'max:20'],
            'config_options' => ['nullable', 'array'],
            'config_options.*' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['integer'],
            'domain_mode' => ['nullable', 'string', 'in:register,transfer,existing'],
            'domain_name' => ['nullable', 'string', 'max:253'],
        ]);

        $domain = $request->string('domain_name')->trim()->lower()->toString();
        $item = new CartItem(
            key: 'preview',
            kind: CartItemKind::Product,
            productId: $product,
            cycle: BillingCycle::tryFrom($request->string('cycle')->toString()) ?? BillingCycle::Monthly,
            configOptions: array_map(intval(...), array_filter((array) $request->input('config_options', []), is_numeric(...))),
            domainMode: $request->input('domain_mode'),
            domainName: DomainGuard::isValidName($domain) ? $domain : null,
            addonIds: array_values(array_map(intval(...), (array) $request->input('addon_ids', []))),
        );

        $client = $customers->clientFor($request->user(), $request->session());
        $priced = $pricer->price([$item], $this->carts->currencyFor($request), null, $client);

        return response()->json([
            'item' => $priced->items[0]->toArray(),
            'currency' => $priced->currency,
            'subtotal' => $priced->subtotal(),
        ]);
    }

    public function domains(Request $request, DomainPricing $pricing, CustomerResolver $customers): Response
    {
        $currency = $this->carts->currencyFor($request);
        $clientId = $customers->clientFor($request->user(), $request->session())?->id;
        $search = $request->string('domain')->trim()->lower()->toString();
        $results = [];

        if ($search !== '') {
            $sld = $pricing->split($search)[0] ?? explode('.', $search)[0];
            $candidates = array_values(array_unique([$search, ...array_map(fn (string $tld): string => $sld.$tld, self::SUGGESTED_TLDS)]));

            foreach ($candidates as $candidate) {
                if (! DomainGuard::isValidName($candidate) || ($parts = $pricing->split($candidate)) === null) {
                    continue;
                }

                $results[] = [
                    'domain' => $candidate,
                    'tld' => $parts[1],
                    'register' => $pricing->price($parts[1], 'register', 1, $currency, $clientId),
                    'transfer' => $pricing->price($parts[1], 'transfer', 1, $currency, $clientId),
                    'renew' => $pricing->price($parts[1], 'renew', 1, $currency, $clientId),
                    'register_terms' => $pricing->terms($parts[1], 'register', $currency),
                    'transfer_terms' => $pricing->terms($parts[1], 'transfer', $currency),
                ];
            }
        }

        return Inertia::render('public/store/domains', [
            'search' => $search,
            'results' => $results,
            'currency' => $currency,
            ...$this->carts->storeProps($request),
        ]);
    }

    public function currency(Request $request): RedirectResponse
    {
        $request->validate(['currency' => ['required', 'string', 'size:3']]);
        $code = $request->string('currency')->upper()->toString();

        if (Currency::query()->where('code', $code)->exists()) {
            $request->session()->put(CartManager::SESSION_CURRENCY, $code);
            $cart = $this->carts->find($request);

            if ($cart !== null && $cart->client_id === null) {
                $cart->currency = $code;
                $this->carts->save($cart, $request);
            }
        }

        return back();
    }
}
