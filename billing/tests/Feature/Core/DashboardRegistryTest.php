<?php

use App\Models\User;
use App\Support\Dashboard\Dashboard;

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
