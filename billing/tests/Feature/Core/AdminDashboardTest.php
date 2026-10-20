<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
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

it('counts new clients per local week for the last 12 weeks', function () {
    app(Settings::class)->set('system.timezone', 'Asia/Kathmandu'); // UTC+5:45
    $this->travelTo('2026-10-18 06:00:00');                          // Sunday 11:45 local; weeks start on Monday
    foreach (['2026-07-26 17:00:00', '2026-07-26 20:00:00', '2026-08-05 09:00:00', '2026-10-11 20:00:00', '2026-10-18 05:00:00'] as $createdAt) {
        Client::factory()->create(['created_at' => $createdAt]); // the first is 07-26 22:45 local, before the window
    }

    $chart = collect(app(Dashboard::class)->resolveWidgets('admin', actingAsStaff(['core.clients.view']), deferred: true))->firstWhere('key', 'core.clients.signups');
    $points = collect($chart['series'][0]['points'])->pluck('y', 'x');

    expect($points)->toHaveCount(12)
        ->and($points->keys()->first())->toBe('2026-07-27')
        ->and($points->filter()->all())->toBe(['2026-07-27' => 1, '2026-08-03' => 1, '2026-10-12' => 2])
        ->and($chart['description'])->toBe('Per week, last 12 weeks · 4 new clients');
});
