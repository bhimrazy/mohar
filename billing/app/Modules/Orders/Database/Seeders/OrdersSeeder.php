<?php

namespace App\Modules\Orders\Database\Seeders;

use App\Contracts\Catalog\PriceBook;
use App\Enums\BillingCycle;
use App\Modules\Core\Models\Client;
use App\Modules\Orders\Actions\AcceptOrder;
use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\MarkOrderFraud;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Enums\PromotionType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Promotion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Demo promotions and orders.
 *
 * Orders are placed through PlaceOrder, so services, domains and invoices are created through the
 * sibling contracts (the local demo bindings until Catalog/Services/Domains/Billing are merged).
 * Assumes catalog product ids 1 (Starter Hosting), 2 (Business Hosting), 4 (Cloud VPS S) and
 * addon id 1 (backups) as in the demo catalog; orders whose products don't exist are skipped.
 */
class OrdersSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPromotions();

        if (app()->bound(PriceBook::class) && Order::query()->doesntExist()) {
            $this->seedOrders();
        }
    }

    private function seedPromotions(): void
    {
        $promotions = [
            'WELCOME25' => ['type' => PromotionType::Percent, 'value' => '25.00', 'recurring' => true, 'recur_count' => 3, 'new_clients_only' => true, 'once_per_client' => true, 'admin_notes' => 'New customer offer: 25% off the first 3 renewals too.'],
            'SAVE10' => ['type' => PromotionType::Fixed, 'value' => '10.00', 'min_cart_amount' => 5000, 'max_uses' => 500],
            'FREESETUP' => ['type' => PromotionType::FreeSetup, 'value' => '0.00', 'applies_to' => ['product_ids' => [4, 5]]],
            'VPSYEAR' => ['type' => PromotionType::Percent, 'value' => '15.00', 'cycles' => ['annually'], 'applies_to' => ['product_ids' => [4, 5]], 'expires_on' => now()->addMonths(2)->toDateString()],
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

        $orders = [
            [$demo, [$this->product(2, 'annually', 'demolabs.com', 'register', [1])], 'SAVE10', 'accept', 20],
            [$demo, [$this->product(4, 'monthly')], null, null, 2],
            [$others[0] ?? $demo, [$this->product(1, 'monthly', 'northwind.test', 'existing')], 'WELCOME25', null, 0],
            [$others[1] ?? $demo, [$this->product(4, 'monthly')], 'FREESETUP', 'accept', 6],
            [$others[2] ?? $demo, [$this->product(1, 'monthly', 'cheap-pills.test', 'existing')], null, 'fraud', 1],
            [$others[3] ?? $demo, [$this->product(2, 'monthly', 'globex.test', 'existing')], null, 'cancel', 12],
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
    private function product(int $productId, string $cycle, ?string $domain = null, string $mode = 'existing', array $addonIds = []): CartItem
    {
        return new CartItem(
            key: CartItem::newKey(),
            kind: CartItemKind::Product,
            productId: $productId,
            cycle: BillingCycle::from($cycle),
            domainMode: $domain === null ? null : $mode,
            domainName: $domain,
            addonIds: $addonIds,
        );
    }
}
