<?php

use App\Support\Dashboard\Dashboard;
use Inertia\Testing\AssertableInertia as Assert;

it('passes kernel widgets, deferred widgets and todos to the admin dashboard', function () {
    $dashboard = app(Dashboard::class);
    $dashboard->widget('admin', 'test.list', 'list', fn () => ['title' => 'Recent orders', 'items' => [['title' => 'ORD-1', 'status' => 'pending']]]);
    $dashboard->widget('admin', 'test.chart', 'chart', fn () => ['title' => 'Income', 'kind' => 'line', 'series' => []], deferred: true);
    $dashboard->todo('admin', 'test.todo', fn () => ['label' => 'Tickets awaiting reply', 'count' => 3, 'tone' => 'warning']);
    $dashboard->todo('admin', 'test.zero', fn () => ['label' => 'Nothing', 'count' => 0]);

    actingAsStaff();

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('widgets', fn ($widgets) => collect($widgets)->contains('key', 'test.list') && ! collect($widgets)->contains('key', 'test.chart'))
            ->where('todos', fn ($todos) => collect($todos)->contains('label', 'Tickets awaiting reply') && ! collect($todos)->contains('key', 'test.zero'))
            ->missing('deferredWidgets')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('deferredWidgets', fn ($deferred) => collect($deferred)->contains('key', 'test.chart'))));
});
