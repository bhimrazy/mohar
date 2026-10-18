<?php

use App\Models\User;
use App\Support\Dashboard\Dashboard;
use App\Support\Dashboard\Trend;

test('widgets are filtered by permission, ordered and typed', function () {
    $dashboard = new Dashboard;
    $dashboard->card('admin', 'a.stat', fn () => ['label' => 'Clients', 'value' => 3], order: 20);
    $dashboard->widget('admin', 'b.list', 'list', fn () => ['title' => 'Recent', 'items' => []], 'billing.invoices.view', order: 10, size: 'lg');
    $dashboard->widget('admin', 'c.chart', 'chart', fn () => ['title' => 'Income', 'kind' => 'line', 'series' => []], order: 30, deferred: true);

    $staff = User::factory()->staff(['billing.*'])->create();
    $limited = User::factory()->staff(['support.*'])->create();

    expect(array_column($dashboard->resolveWidgets('admin', $staff), 'key'))->toBe(['b.list', 'a.stat', 'c.chart'])
        ->and(array_column($dashboard->resolveWidgets('admin', $limited), 'key'))->toBe(['a.stat', 'c.chart'])
        ->and(array_column($dashboard->resolveWidgets('admin', $staff, deferred: false), 'key'))->toBe(['b.list', 'a.stat'])
        ->and($dashboard->resolveWidgets('admin', $staff)[0]['size'])->toBe('lg')
        ->and(array_column($dashboard->resolve('admin', $staff), 'key'))->toBe(['a.stat']);
});

test('todo items with zero counts are hidden', function () {
    $dashboard = new Dashboard;
    $dashboard->todo('admin', 'orders.pending', fn () => ['label' => 'Pending orders', 'count' => 2, 'href' => '/x']);
    $dashboard->todo('admin', 'tickets.awaiting', fn () => ['label' => 'Tickets awaiting reply', 'count' => 0]);

    expect($dashboard->resolveTodos('admin', User::factory()->staff()->create()))
        ->toHaveCount(1)
        ->sequence(fn ($todo) => $todo->key->toBe('orders.pending'));
});

test('health checks are aggregated in order and filtered by permission', function () {
    $dashboard = new Dashboard;
    $dashboard->health('admin', 'b.queue', fn () => ['label' => 'Queue', 'value' => 'OK', 'tone' => 'success'], order: 20);
    $dashboard->health('admin', 'a.gateways', fn () => ['label' => 'Gateways', 'value' => '1 to review', 'tone' => 'danger'], 'billing.transactions.view', order: 10);

    expect(array_column($dashboard->resolveHealth('admin', User::factory()->staff(['billing.*'])->create()), 'key'))->toBe(['a.gateways', 'b.queue'])
        ->and($dashboard->resolveHealth('admin', User::factory()->staff(['support.*'])->create()))->toBe([['key' => 'b.queue', 'label' => 'Queue', 'value' => 'OK', 'tone' => 'success']])
        ->and($dashboard->resolveHealth('client', null))->toBe([]);
});

test('breakdown is a widget type and optional payload fields pass through untouched', function () {
    $dashboard = new Dashboard;
    $dashboard->widget('admin', 'x.split', 'breakdown', fn () => ['title' => 'Split', 'rows' => [['key' => 'USD', 'label' => 'US Dollar', 'share' => 100]], 'variants' => ['USD' => ['title' => 'Split']]]);
    $dashboard->card('admin', 'x.kpi', fn () => ['label' => 'MRR', 'value' => '$1', 'sparkline' => [1, 2], 'trend' => ['value' => '5%', 'direction' => 'up']]);

    $widgets = collect($dashboard->resolveWidgets('admin', User::factory()->staff()->create()))->keyBy('key');

    expect($widgets['x.split']['type'])->toBe('breakdown')
        ->and($widgets['x.split']['variants'])->toBe(['USD' => ['title' => 'Split']])
        ->and($widgets['x.kpi'])->toMatchArray(['sparkline' => [1, 2], 'trend' => ['value' => '5%', 'direction' => 'up']]);
});

test('trend pills compare against the previous period and say nothing without one', function () {
    expect(Trend::percent(1_200, 1_000))->toBe(['value' => '20.0%', 'direction' => 'up', 'upIsGood' => true])
        ->and(Trend::percent(500, 1_000, upIsGood: false))->toBe(['value' => '50.0%', 'direction' => 'down', 'upIsGood' => false])
        ->and(Trend::percent(1_000, 1_000))->toMatchArray(['direction' => 'flat'])
        ->and(Trend::percent(1_000, 0))->toBeNull()
        ->and(Trend::delta(9, 6, upIsGood: false))->toBe(['value' => '+3', 'direction' => 'up', 'upIsGood' => false]);
});
