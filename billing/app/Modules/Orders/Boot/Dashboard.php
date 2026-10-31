<?php

namespace App\Modules\Orders\Boot;

use App\Contracts\Core\CompanyClock;
use App\Models\User;
use App\Modules\Core\Support\RequestMemo;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Queries\OrdersDashboard;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Dashboard\Dashboard as DashboardRegistry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;

final class Dashboard
{
    public function __invoke(Container $app): void
    {
        $dashboard = $app->make(DashboardRegistry::class);
        $dashboard->card('admin', 'orders.pending', fn (?User $user): array => [
            'label' => 'Pending orders',
            'value' => RequestMemo::remember('orders.pending_count', fn (): int => Order::query()->where('status', OrderStatus::Pending)->count()),
            'href' => route('admin.orders.index', ['status' => OrderStatus::Pending->value]),
            'icon' => 'ShoppingCart',
        ], 'orders.orders.view', 20, mirrorsTodo: true);
        $dashboard->card('admin', 'orders.today', fn (?User $user): array => [
            'label' => 'Orders today',
            'value' => Order::query()->where('created_at', '>=', $app->make(CompanyClock::class)->now()->startOfDay()->utc())->count(),
            'href' => route('admin.orders.index', ['from' => $app->make(CompanyClock::class)->now()->toDateString()]),
            'icon' => 'TrendingUp',
        ], 'orders.orders.view', 21);

        $dashboard->widget('admin', 'orders.recent', 'list', fn (?User $user): array => [
            'title' => 'Recent orders',
            'href' => route('admin.orders.index'),
            'empty' => 'No orders yet.',
            'items' => Order::query()->with(['client', 'items' => fn ($query) => $query->whereNull('parent_id')->orderBy('id')])
                ->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Order $order): array => [
                    'title' => $order->client->display_name,
                    'subtitle' => collect(["#{$order->number}", $order->items->first()?->description, $order->created_at?->diffForHumans()])->filter()->implode(' · '),
                    'avatar' => $order->client->display_name,
                    'href' => route('admin.orders.show', $order),
                    'meta' => app(MoneyFormatter::class)->format(Money::of($order->total, $order->currency)),
                    'money' => ['amount' => $order->total, 'currency' => $order->currency],
                    'status' => $order->status->value,
                ])->all(),
        ], 'orders.orders.view', 50, 'md');

        $app->make(OrdersDashboard::class)->register($dashboard);

        $dashboard->todo('admin', 'orders.pending', fn (?User $user): array => [
            'label' => 'Orders awaiting review',
            'count' => RequestMemo::remember('orders.pending_count', fn (): int => Order::query()->where('status', OrderStatus::Pending)->count()),
            'href' => route('admin.orders.index', ['status' => OrderStatus::Pending->value]),
            'tone' => 'warning',
            'icon' => 'ShoppingCart',
            'hint' => self::oldestPendingHint(),
        ], 'orders.orders.view', 10);
        $dashboard->todo('admin', 'orders.fraud', fn (?User $user): array => [
            'label' => 'Orders flagged as fraud',
            'count' => Order::query()->where('status', OrderStatus::Fraud)->count(),
            'href' => route('admin.orders.index', ['status' => OrderStatus::Fraud->value]),
            'tone' => 'danger',
            'icon' => 'ShieldAlert',
        ], 'orders.orders.view', 11);
    }

    private static function oldestPendingHint(): ?string
    {
        $oldest = Order::query()->where('status', OrderStatus::Pending)->min('created_at');

        return $oldest === null ? null : 'Oldest waiting '.CarbonImmutable::parse((string) $oldest)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);
    }
}
