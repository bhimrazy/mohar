<?php

namespace Tests\Fakes\Orders;

use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\InvoiceQueries;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\TaxCalculator;
use App\Contracts\Catalog\PriceBook;
use App\Contracts\Domains\DomainPricing;
use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Services\ClientServiceDirectory;
use App\Contracts\Services\ServiceRegistry;
use App\Enums\BillingCycle;
use App\Modules\Core\Models\Client;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Models\Cart;
use Illuminate\Support\Str;
use Tests\Fakes\Billing\FakeInvoiceQueries;
use Tests\Fakes\Catalog\FakePriceBook;

/**
 * Binds fakes for every contract Orders consumes and exposes them to the test.
 */
final class OrdersFakes
{
    public function __construct(
        public FakePriceBook $catalog = new FakePriceBook(anyUpgrade: true, driver: 'demo', type: 'shared_hosting', requiresDomain: false),
        public FakeDomains $domains = new FakeDomains,
        public FakeServiceRegistry $services = new FakeServiceRegistry,
        public FakeBilling $billing = new FakeBilling,
        public FakeGateways $gateways = new FakeGateways,
        public FakeInvoiceQueries $invoices = new FakeInvoiceQueries,
        public FakeClientServices $clientServices = new FakeClientServices,
    ) {}

    /**
     * @param  bool  $realCatalog  keep the real Catalog PriceBook (integration tests)
     */
    public static function bind(bool $realCatalog = false): self
    {
        $fakes = new self;
        $app = app();

        if (! $realCatalog) {
            $app->instance(PriceBook::class, $fakes->catalog);
        }

        $app->instance(DomainPricing::class, $fakes->domains);
        $app->instance(DomainRegistry::class, $fakes->domains);
        $app->instance(ServiceRegistry::class, $fakes->services);
        $app->instance(Invoicer::class, $fakes->billing);
        $app->instance(TaxCalculator::class, $fakes->billing);
        $app->instance(GatewayDirectory::class, $fakes->gateways);
        $app->instance(InvoiceQueries::class, $fakes->invoices);
        $app->instance(ClientServiceDirectory::class, $fakes->clientServices);

        return $fakes;
    }

    /**
     * @param  list<int>  $addonIds
     */
    public static function productItem(int $productId, string $cycle = 'monthly', ?string $domain = null, string $mode = 'existing', array $addonIds = []): CartItem
    {
        return new CartItem(
            key: CartItem::newKey(),
            kind: CartItemKind::Product,
            productId: $productId,
            cycle: BillingCycle::from($cycle),
            domainMode: $domain === null ? null : $mode,
            domainName: $domain,
            eppCode: $mode === 'transfer' ? 'EPP-123' : null,
            addonIds: $addonIds,
        );
    }

    public static function domainItem(string $domain, int $years = 1, bool $transfer = false): CartItem
    {
        return new CartItem(
            key: CartItem::newKey(),
            kind: $transfer ? CartItemKind::DomainTransfer : CartItemKind::DomainRegister,
            domainName: $domain,
            eppCode: $transfer ? 'EPP-123' : null,
            years: $years,
        );
    }

    /**
     * @param  list<CartItem>  $items
     */
    public static function cartFor(?Client $client, array $items, ?string $promotionCode = null, string $currency = 'USD'): Cart
    {
        $cart = new Cart([
            'token' => (string) Str::uuid(),
            'client_id' => $client?->id,
            'currency' => $client->currency ?? $currency,
            'promotion_code' => $promotionCode,
            'expires_at' => now()->addDays(7),
        ]);
        $cart->setCartItems($items);
        $cart->save();

        return $cart;
    }

    /**
     * @param  list<CartItem>  $items
     */
    public static function checkout(Client $client, array $items, ?string $promotionCode = null, string $gateway = 'bank_transfer', ?string $key = null, bool $skipFraud = false, ?string $referralCode = null): CheckoutData
    {
        return new CheckoutData(
            items: $items,
            currency: $client->currency,
            paymentGateway: $gateway,
            promotionCode: $promotionCode,
            client: $client,
            ipAddress: '203.0.113.10',
            idempotencyKey: $key ?? (string) Str::uuid(),
            referralCode: $referralCode,
            skipFraud: $skipFraud,
        );
    }
}
