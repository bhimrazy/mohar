<?php

namespace App\Modules\Orders\Http\Controllers\Public;

use App\Contracts\Catalog\PriceBook;
use App\Contracts\Domains\DomainNameRules;
use App\Contracts\Domains\DomainPricing;
use App\Data\Catalog\ProductSelection;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Pricing\CartPricer;
use App\Modules\Orders\Services\CartManager;
use App\Modules\Orders\Services\CustomerResolver;
use App\Modules\Orders\Services\DomainGuard;
use App\Modules\Orders\Services\TldAttributeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    /** First path segments under /store that are not group slugs; Catalog refuses them as a group slug. */
    public const RESERVED_SLUGS = ['cart', 'checkout', 'domains', 'currency', 'orders', 'products'];

    private const SUGGESTED_TLDS = ['.com', '.net', '.org', '.io', '.dev'];

    public function __construct(
        private PriceBook $priceBook,
        private CartManager $carts,
        private DomainNameRules $nameRules,
    ) {}

    public function index(Request $request): Response
    {
        $currency = $this->carts->currencyFor($request);
        $groups = $this->withUrls($request, $this->priceBook->storefront($currency));

        return $this->storeIndex($request, $currency, $groups);
    }

    public function group(Request $request, string $group): Response
    {
        $currency = $this->carts->currencyFor($request);
        $groups = array_values(array_filter($this->withUrls($request, $this->priceBook->storefront($currency)), fn (array $candidate): bool => $candidate['slug'] === $group));

        if ($groups === []) {
            $elsewhere = $this->currenciesSelling(fn (string $code): bool => collect($this->priceBook->storefront($code))->contains('slug', $group), $currency);
            abort_if($elsewhere === [], 404);
        }

        return $this->storeIndex($request, $currency, $groups, $groups[0] ?? null, $groups === [] ? $group : null);
    }

    /**
     * Product URLs on the slug route, keeping a domain the visitor already owns.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function withUrls(Request $request, array $groups): array
    {
        $query = ($domain = $this->existingDomain($request)) === null ? [] : ['domain' => $domain];

        foreach ($groups as &$group) {
            foreach ($group['products'] as &$product) {
                $product['url'] = route('store.products.show', ['group' => $group['slug'], 'product' => $product['slug'], ...$query]);
            }
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, mixed>|null  $current  the group a group page shows
     */
    private function storeIndex(Request $request, string $currency, array $groups, ?array $current = null, ?string $slugElsewhere = null): Response
    {
        return Inertia::render('public/store/index', [
            'groups' => $groups,
            'group' => $current === null ? null : ['id' => $current['id'], 'name' => $current['name'], 'slug' => $current['slug'], 'url' => route('store.groups.show', $current['slug'])],
            'currency' => $currency,
            // Products unpriced in the visitor's currency are left out; an empty store says where products are sold.
            'sold_in' => $groups === [] ? $this->currenciesSelling(fn (string $code): bool => $slugElsewhere === null ? $this->priceBook->storefront($code) !== [] : collect($this->priceBook->storefront($code))->contains('slug', $slugElsewhere), $currency) : [],
            'existingDomain' => $this->existingDomain($request),
            'hide_tax_note' => $groups === [],
            'account_currency' => $request->user() === null ? null : $currency,
            'support_url' => Route::has('support.tickets.create') ? route('support.tickets.create') : null,
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

        return DomainGuard::isValidName($domain) && $this->nameRules->registryLevelError($domain) === null ? $domain : null;
    }

    /**
     * Other currencies where $sells is true, default currency first.
     *
     * @param  callable(string): bool  $sells
     * @return list<string>
     */
    private function currenciesSelling(callable $sells, string $current): array
    {
        $codes = Currency::query()->where('code', '!=', $current)->orderByDesc('is_default')->orderBy('code')->pluck('code')->map(strval(...))->all();

        return array_values(array_filter($codes, $sells));
    }

    /**
     * The numeric URL older links and carts used: permanent redirect to the slug URL, query string kept.
     */
    public function legacyProduct(Request $request, int $product, CustomerResolver $customers, TldAttributeInput $tldAttributes): Response|RedirectResponse
    {
        $summary = $this->priceBook->product($product);
        abort_if($summary === null || ! $summary->active, 404);

        return $summary->slug === null || $summary->groupSlug === null
            ? $this->configure($request, $summary, $customers, $tldAttributes)
            : $this->canonical($request, $summary->groupSlug, $summary->slug);
    }

    public function show(Request $request, string $group, string $product, CustomerResolver $customers, TldAttributeInput $tldAttributes): Response|RedirectResponse
    {
        $summary = $this->priceBook->productBySlug($product);
        abort_if($summary === null || ! $summary->active, 404);

        if ($summary->groupSlug !== null && $summary->groupSlug !== $group) {
            return $this->canonical($request, $summary->groupSlug, $product);
        }

        return $this->configure($request, $summary, $customers, $tldAttributes);
    }

    private function canonical(Request $request, string $group, string $product): RedirectResponse
    {
        $url = route('store.products.show', ['group' => $group, 'product' => $product]);

        return redirect()->to($request->getQueryString() === null ? $url : $url.'?'.$request->getQueryString(), 301);
    }

    private function configure(Request $request, ProductSummary $summary, CustomerResolver $customers, TldAttributeInput $tldAttributes): Response
    {
        $currency = $this->carts->currencyFor($request);
        $product = $summary->id;

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
                'group_url' => $summary->groupSlug === null ? null : route('store.groups.show', $summary->groupSlug),
                'requires_domain' => $summary->requiresDomain,
                'in_stock' => $summary->stock === null || $summary->stock > 0,
            ],
            'currency' => $currency,
            'cycles' => $cycles,
            'hide_tax_note' => $cycles === [],
            'account_currency' => $request->user() === null ? null : $currency,
            'support_url' => Route::has('support.tickets.create') ? route('support.tickets.create') : null,
            'sold_in' => $cycles === [] ? $this->currenciesSelling(fn (string $code): bool => $this->priceBook->availableCycles($product, $code) !== [], $currency) : [],
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
                'tld_attributes' => $editing->tldAttributes,
            ] : null,
            'signed_in' => $clientId !== null,
            'existing_domain' => $this->existingDomain($request),
            'tld_attribute_fields' => $tldAttributes->props(),
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

        $domain = DomainGuard::normalise($request->string('domain_name')->toString());
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

    public function domains(Request $request, DomainPricing $pricing, CustomerResolver $customers, TldAttributeInput $tldAttributes): Response
    {
        $currency = $this->carts->currencyFor($request);
        $clientId = $customers->clientFor($request->user(), $request->session())?->id;
        $search = $request->string('domain')->trim()->lower()->toString();
        $results = [];

        if ($search !== '') {
            $sld = $pricing->split($search)[0] ?? explode('.', $search)[0];
            $candidates = array_values(array_unique([$search, ...array_map(fn (string $tld): string => $sld.$tld, $this->searchTlds($pricing, $currency, $clientId))]));

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
                    'register_terms' => $pricing->terms($parts[1], 'register', $currency, $clientId),
                    'transfer_terms' => $pricing->terms($parts[1], 'transfer', $currency, $clientId),
                ];
            }
        }

        return Inertia::render('public/store/domains', [
            'search' => $search,
            'results' => $results,
            'currency' => $currency,
            // Availability comes from the Domains lookup, read by the page once it has loaded.
            'lookup_url' => Route::has('domains.lookup') ? route('domains.lookup') : null,
            'tld_attribute_fields' => $tldAttributes->props(),
            ...$this->carts->storeProps($request),
        ]);
    }

    /**
     * Extensions priced next to the name searched: the ones the host spotlights, else a common set.
     *
     * @return list<string>
     */
    private function searchTlds(DomainPricing $pricing, string $currency, ?int $clientId): array
    {
        $featured = array_column(array_filter($pricing->catalog($currency, $clientId), fn (array $row): bool => $row['featured']), 'tld');

        return $featured === [] ? self::SUGGESTED_TLDS : array_slice($featured, 0, 8);
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
