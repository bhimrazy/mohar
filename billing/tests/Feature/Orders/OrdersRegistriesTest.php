<?php

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Dashboard\Dashboard;
use App\Support\Navigation\Navigation;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingsRegistry;
use Tests\Fakes\Orders\OrdersFakes;

beforeEach(function () {
    Currency::factory()->create();
});

it('shows pending and today order cards to staff with order access', function () {
    $user = actingAsStaff(['orders.orders.view']);
    Order::factory()->count(2)->create();
    Order::factory()->status(OrderStatus::Active)->create(['created_at' => now()->subDays(3)]);

    $cards = collect(app(Dashboard::class)->resolve('admin', $user))->keyBy('key');

    expect($cards['orders.pending']['value'])->toBe(2)
        ->and($cards['orders.today']['value'])->toBe(2);
});

it('lists recent orders and flags pending and fraud orders as to-dos', function () {
    $user = actingAsStaff(['orders.orders.view']);
    $recent = Order::factory()->create(['created_at' => now()]);
    Order::factory()->create(['created_at' => now()->subDay()]);
    Order::factory()->fraud()->create(['created_at' => now()->subDays(2)]);

    $widget = collect(app(Dashboard::class)->resolveWidgets('admin', $user))->firstWhere('key', 'orders.recent');
    $todos = collect(app(Dashboard::class)->resolveTodos('admin', $user))->keyBy('key');

    expect($widget['type'])->toBe('list')
        ->and($widget['items'])->toHaveCount(3)
        ->and($widget['items'][0]['title'])->toBe("#{$recent->number}")
        ->and($todos['orders.pending']['count'])->toBe(2)
        ->and($todos['orders.fraud']['count'])->toBe(1);
});

it('shows the pending count on the Orders nav item and offers a quick-create action', function () {
    $user = actingAsStaff(['orders.orders.view', 'orders.orders.create']);
    Order::factory()->count(2)->create();

    $orders = collect(app(Navigation::class)->forUser('admin', $user))->firstWhere('title', 'Orders')['items'][0];

    expect($orders['badge'])->toBe(2)
        ->and(app(Navigation::class)->quickActions($user))->toContain(['title' => 'New order', 'href' => route('admin.orders.create'), 'icon' => 'ShoppingCart']);
});

it('hides order cards from staff without order access', function () {
    $user = actingAsStaff(['support.*']);

    expect(collect(app(Dashboard::class)->resolve('admin', $user))->pluck('key'))->not->toContain('orders.pending');
});

it('finds orders by number in global search', function () {
    $user = actingAsStaff(['orders.orders.view']);
    $order = Order::factory()->create(['number' => '9876543210']);

    $results = app(GlobalSearch::class)->search('654321', $user);

    expect($results[0]['group'])->toBe('Orders')
        ->and($results[0]['results'][0]['href'])->toBe(route('admin.orders.show', $order));
});

it('resolves order merge fields including the client id', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create(['description' => 'Starter Hosting', 'first_amount' => 1000, 'setup_fee' => 0, 'discount_amount' => 0]);

    $fields = app(MergeFieldRegistry::class)->resolve('order', $order->id);

    expect($fields)->client_id->toBe($order->client_id)->number->toBe($order->number)
        ->and($fields['items'][0])->toBe(['description' => 'Starter Hosting', 'amount' => 1000, 'cycle' => 'monthly']);
});

it('registers navigation, permissions and settings', function () {
    $user = actingAsStaff(['orders.*']);

    $groups = collect(app(Navigation::class)->forUser('admin', $user))->keyBy('title');

    expect(collect($groups['Orders']['items'])->pluck('title')->all())->toBe(['Orders', 'Promotions'])
        ->and(app(PermissionRegistry::class)->exists('orders.orders.mark_fraud'))->toBeTrue()
        ->and(app(SettingsRegistry::class)->sections()['orders']['permission'])->toBe('orders.fraud.configure');
});

it('cancels stale unpaid pending orders in the automation run', function () {
    $fakes = OrdersFakes::bind();
    config(['orders.auto_cancel_unpaid_days' => 3]);
    $stale = Order::factory()->create(['created_at' => now()->subDays(5)]);
    $fresh = Order::factory()->create();
    $paid = Order::factory()->create(['created_at' => now()->subDays(5)]);
    $fakes->billing->statuses = [$stale->invoice_id => 'unpaid', $fresh->invoice_id => 'unpaid', $paid->invoice_id => 'paid'];

    $this->artisan('automation:run', ['--only' => 'orders.cancel_stale_pending'])->assertSuccessful();

    expect($stale->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($fresh->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($paid->fresh()->status)->toBe(OrderStatus::Pending);
});
