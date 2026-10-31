<?php

namespace App\Modules\Orders\Queries;

use App\Contracts\Catalog\PriceBook;
use App\Contracts\Core\CompanyClock;
use App\Models\User;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Dashboard\Dashboard;
use Carbon\CarbonImmutable;

/**
 * Orders' admin dashboard chart: new orders per day for the last 14 days, stacked by product type. Each order
 * counts once, under its first top-level item, so a stack's height is that day's order count.
 */
class OrdersDashboard
{
    public const DAYS = 14;

    /** Series in fixed order (colour follows the category, never its rank). */
    public const CATEGORIES = ['hosting' => 'Hosting', 'servers' => 'Servers', 'domains' => 'Domains', 'other' => 'Other'];

    public function __construct(private CompanyClock $clock) {}

    public function register(Dashboard $dashboard): void
    {
        $dashboard->widget('admin', 'orders.by_type', 'chart', fn (?User $user): array => $this->byType(), 'orders.orders.view', 30, 'md', deferred: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function byType(): array
    {
        // Company-local midnight 13 days ago; converted to UTC only for the query (created_at is stored in UTC).
        $start = $this->clock->now()->startOfDay()->subDays(self::DAYS - 1);
        $days = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $days[$start->addDays($offset)->toDateString()] = array_fill_keys(array_keys(self::CATEGORIES), 0);
        }

        $orders = Order::query()
            ->where('created_at', '>=', $start->utc())
            ->where('status', '!=', OrderStatus::Fraud)
            ->orderBy('id')
            ->get(['id', 'created_at']);
        $firstItems = OrderItem::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get(['order_id', 'item_type', 'product_id'])
            ->unique('order_id')
            ->keyBy('order_id');
        $types = [];

        foreach ($orders as $order) {
            $day = CarbonImmutable::parse($order->created_at, 'UTC')->setTimezone($this->clock->timezone())->toDateString();

            if (isset($days[$day])) {
                $days[$day][$this->category($firstItems->get($order->id), $types)]++;
            }
        }

        $total = array_sum(array_map('array_sum', $days));
        $slots = array_flip(array_keys(self::CATEGORIES));
        $categories = array_filter(self::CATEGORIES, fn (string $key): bool => array_sum(array_column($days, $key)) > 0, ARRAY_FILTER_USE_KEY) ?: ['hosting' => 'Hosting'];

        return [
            'title' => 'New orders',
            'description' => sprintf('Last %d days · %s', self::DAYS, trans_choice('{0} no orders|{1} 1 order|[2,*] :count orders', $total)),
            'kind' => 'bar',
            'stacked' => true,
            'xFormat' => 'dayOfMonth',
            'href' => route('admin.orders.index'),
            'empty' => 'No orders in the last '.self::DAYS.' days.',
            // Empty categories are left out; `slot` keeps each category on its own chart colour.
            'series' => array_map(fn (string $key, string $label): array => [
                'name' => $label,
                'slot' => $slots[$key] + 1,
                'points' => array_map(fn (string $day): array => ['x' => $day, 'y' => $days[$day][$key]], array_keys($days)),
            ], array_keys($categories), $categories),
        ];
    }

    /**
     * @param  array<int, string>  $types  product type per product id, filled as products are looked up
     */
    private function category(?OrderItem $item, array &$types): string
    {
        if ($item === null) {
            return 'other';
        }

        if ($item->item_type === OrderItemType::Domain) {
            return 'domains';
        }

        if ($item->item_type !== OrderItemType::Service || $item->product_id === null || ! app()->bound(PriceBook::class)) {
            return 'other';
        }

        $types[$item->product_id] ??= app(PriceBook::class)->product($item->product_id)->type ?? 'other';

        return match ($types[$item->product_id]) {
            'shared_hosting', 'reseller_hosting' => 'hosting',
            'server' => 'servers',
            default => 'other',
        };
    }
}
