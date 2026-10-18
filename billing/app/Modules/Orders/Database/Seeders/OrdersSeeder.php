<?php

namespace App\Modules\Orders\Database\Seeders;

use App\Contracts\Catalog\PriceBook;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;
use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Actions\AcceptOrder;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\MarkOrderFraud;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PromotionType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Promotion;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Demo promotions and orders.
 *
 * Orders are placed through PlaceOrder, so services, domains and invoices are created through the
 * sibling contracts. Products are looked up by the Catalog seeder's slugs (starter, pro, business,
 * vps-s, vps-m); orders whose products don't exist are skipped.
 */
class OrdersSeeder extends DemoSeeder
{
    public function run(): void
    {
        if (! app()->bound(PriceBook::class)) {
            return;
        }

        $this->seedPromotions();

        if (Order::query()->doesntExist()) {
            // Placed orders first: their new-client promotions must see clients without order history.
            $this->seedOrders();
            $this->seedHistory();
        }
    }

    /** Monthly list prices in USD cents per demo product slug, and the share of orders each takes. */
    private const HISTORY_MIX = [
        'starter' => [399, 22], 'pro' => [799, 16], 'business' => [1499, 10],
        'vps-s' => [1200, 12], 'vps-m' => [2400, 7], 'ssl-dv' => [2900, 8], 'domain' => [1299, 25],
    ];

    /**
     * Demo order history for the dashboard's "New orders" chart: 30 days of accepted (and a few cancelled) orders
     * across product types. Rows are written directly (no invoices or services); demo installs only.
     */
    private function seedHistory(): void
    {
        $clients = Client::query()->where('status', 'active')->orderBy('id')->get(['id', 'currency']);
        $usd = Currency::query()->where('code', 'USD')->first();

        if (app()->isProduction() || $clients->isEmpty()) {
            return;
        }

        mt_srand(2027);
        $currencies = Currency::query()->get()->keyBy('code');
        $products = collect(self::HISTORY_MIX)->except('domain')
            ->map(fn (array $plan, string $slug): ?ProductSummary => app(PriceBook::class)->productBySlug($slug))->filter();

        for ($daysAgo = 30; $daysAgo >= 1; $daysAgo--) {
            $day = now()->subDays($daysAgo);
            $count = $day->isWeekend() ? mt_rand(0, 3) : mt_rand(2, 7);

            for ($n = 0; $n < $count; $n++) {
                $slug = $this->pickWeighted();
                $product = $products->get($slug);
                $client = $clients[mt_rand(0, $clients->count() - 1)];
                $target = $currencies->get($client->currency);
                $amount = $usd !== null && $target !== null ? $usd->convertTo(self::HISTORY_MIX[$slug][0], $target) : self::HISTORY_MIX[$slug][0];
                $at = $day->setTime(mt_rand(7, 22), mt_rand(0, 59));
                $status = mt_rand(1, 100) <= 8 ? OrderStatus::Cancelled : OrderStatus::Active;

                if ($slug !== 'domain' && $product === null) {
                    continue;
                }

                $order = new Order;
                $order->forceFill([
                    'number' => (string) mt_rand(1_000_000_000, 9_999_999_999),
                    'client_id' => $client->id, 'kind' => OrderKind::New, 'status' => $status, 'currency' => $client->currency,
                    'subtotal' => $amount, 'total' => $amount, 'payment_gateway' => 'bank_transfer',
                    'ip_address' => '198.51.100.'.mt_rand(2, 250), 'fraud_provider' => 'rules', 'fraud_score' => 0, 'fraud_decision' => FraudDecision::Pass,
                    'accepted_at' => $status === OrderStatus::Active ? $at->addHours(2) : null,
                    'cancelled_at' => $status === OrderStatus::Cancelled ? $at->addDay() : null,
                    'created_at' => $at, 'updated_at' => $at,
                ])->save();

                $domain = 'demo-'.Str::lower(Str::random(6)).'.com';
                $order->items()->create([
                    'item_type' => $slug === 'domain' ? OrderItemType::Domain : OrderItemType::Service,
                    'product_id' => $product?->id,
                    'status' => $status === OrderStatus::Active ? OrderItemStatus::Active : OrderItemStatus::Cancelled,
                    'description' => $slug === 'domain' ? "Domain registration: {$domain}" : $product?->name,
                    'domain' => $domain,
                    'cycle' => $slug === 'domain' || $slug === 'ssl-dv' ? null : BillingCycle::Monthly,
                    'first_amount' => $amount, 'recurring_amount' => $slug === 'ssl-dv' ? 0 : $amount,
                ]);
            }
        }

        mt_srand();
    }

    private function pickWeighted(): string
    {
        $roll = mt_rand(1, 100);

        foreach (self::HISTORY_MIX as $slug => [, $weight]) {
            if (($roll -= $weight) <= 0) {
                return $slug;
            }
        }

        return 'starter';
    }

    private function seedPromotions(): void
    {
        $promotions = [
            'WELCOME25' => ['type' => PromotionType::Percent, 'value' => '25.00', 'recurring' => true, 'recur_count' => 3, 'new_clients_only' => true, 'once_per_client' => true, 'admin_notes' => 'New customer offer: 25% off the first 3 renewals too.'],
            'SAVE10' => ['type' => PromotionType::Fixed, 'value' => '10.00', 'min_cart_amount' => 5000, 'max_uses' => 500],
            'FREESETUP' => ['type' => PromotionType::FreeSetup, 'value' => '0.00', 'applies_to' => ['product_ids' => $this->productIds(['vps-s', 'vps-m'])]],
            'VPSYEAR' => ['type' => PromotionType::Percent, 'value' => '15.00', 'cycles' => ['annually'], 'applies_to' => ['product_ids' => $this->productIds(['vps-s', 'vps-m'])], 'expires_on' => now()->addMonths(2)->toDateString()],
            'SUMMER' => ['type' => PromotionType::Percent, 'value' => '20.00', 'expired_at' => now()->subMonth(), 'expires_on' => now()->subMonth()->toDateString()],
        ];

        foreach ($promotions as $code => $attributes) {
            Promotion::query()->firstOrCreate(['code' => $code], ['applies_to' => [], 'requires' => [], ...$attributes]);
        }
    }

    private function seedOrders(): void
    {
        $demo = Client::query()->where('email', 'demo@example.com')->first();
        $others = Client::query()->where('email', '!=', 'demo@example.com')->orderBy('id')->limit(4)->get()->all();

        if ($demo === null) {
            return;
        }

        $pro = $this->productIds(['pro'])[0] ?? 0;
        $backups = array_column(app(PriceBook::class)->addonsFor($pro, $demo->currency), 'id')[0] ?? null;

        $orders = [
            [$demo, [$this->product('pro', 'annually', 'demolabs-store.com', 'register', $backups === null ? [] : [$backups])], 'SAVE10', 'accept', 20],
            [$demo, [$this->product('vps-s', 'monthly')], null, null, 2],
            [$others[0] ?? $demo, [$this->product('starter', 'monthly', 'northwind.test', 'existing')], 'WELCOME25', null, 0],
            [$others[1] ?? $demo, [$this->product('vps-s', 'monthly')], 'FREESETUP', 'accept', 6],
            [$others[2] ?? $demo, [$this->product('starter', 'monthly', 'cheap-pills.test', 'existing')], null, 'fraud', 1],
            [$others[3] ?? $demo, [$this->product('business', 'monthly', 'globex.test', 'existing')], null, 'cancel', 12],
        ];

        foreach ($orders as [$client, $items, $promotion, $then, $daysAgo]) {
            try {
                $order = app(PlaceOrder::class)->handle(new CheckoutData(
                    items: $items,
                    currency: $client->currency,
                    paymentGateway: 'bank_transfer',
                    promotionCode: $promotion,
                    client: $client,
                    ipAddress: '203.0.113.'.random_int(2, 250),
                    userAgent: 'Mozilla/5.0 (demo seeder)',
                    idempotencyKey: (string) Str::uuid(),
                    skipFraud: $then !== 'fraud',
                    notifyInvoice: false,
                ))->order;
            } catch (ValidationException) {
                continue;
            }

            match ($then) {
                'accept' => app(AcceptOrder::class)->handle($order->load('items'), invoiceStatus: 'paid'),
                'cancel' => app(CancelOrder::class)->handle($order),
                'fraud' => $order->status->value === 'fraud' ? null : app(MarkOrderFraud::class)->handle($order),
                default => null,
            };

            $order->forceFill(['created_at' => now()->subDays($daysAgo)->subHours(random_int(1, 8))])->save();
        }
    }

    /**
     * @param  list<int>  $addonIds
     */
    private function product(string $slug, string $cycle, ?string $domain = null, string $mode = 'existing', array $addonIds = []): CartItem
    {
        return new CartItem(
            key: CartItem::newKey(),
            kind: CartItemKind::Product,
            productId: $this->productIds([$slug])[0] ?? 0,
            cycle: BillingCycle::from($cycle),
            domainMode: $domain === null ? null : $mode,
            domainName: $domain,
            addonIds: $addonIds,
        );
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function productIds(array $slugs): array
    {
        return array_values(array_filter(array_map(fn (string $slug): ?int => app(PriceBook::class)->productBySlug($slug)?->id, $slugs)));
    }
}
