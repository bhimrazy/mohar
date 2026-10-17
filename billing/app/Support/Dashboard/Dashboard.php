<?php

namespace App\Support\Dashboard;

use App\Models\User;
use Closure;

/**
 * Dashboard registry. Modules contribute in bootModule(); resolvers receive (?User $user, ...$arguments) —
 * for the client area the current Client is the extra argument.
 *
 * Stat card (shortcut):
 *   $dashboard->card('admin', 'billing.overdue', fn ($user) => ['label' => 'Overdue', 'value' => 3, 'href' => ..., 'icon' => 'Receipt'], 'billing.invoices.view');
 *
 * Other widgets — type + payload shape:
 *   'list'  => ['title' => 'Recent orders', 'items' => [['title', 'subtitle'?, 'href'?, 'meta'?, 'status'?]], 'href'? (view all), 'empty'?]
 *   'chart' => ['title' => 'Income', 'kind' => 'line'|'bar', 'currency'? , 'series' => [['name', 'points' => [['x' => '2026-10-01', 'y' => 1234]]]], 'href'?]
 *   $dashboard->widget('admin', 'orders.recent', 'list', fn ($user) => [...], 'orders.orders.view', order: 30, size: 'md');
 *
 * "Needs attention" to-do items (aggregated into one widget, zero counts hidden):
 *   $dashboard->todo('admin', 'support.awaiting', fn ($user) => ['label' => 'Tickets awaiting reply', 'count' => 4, 'href' => ..., 'tone' => 'warning'], 'support.tickets.view');
 *
 * Sizes: 'sm' (1/4 row), 'md' (1/2), 'lg' (full). Heavy widgets set $deferred = true (loaded after first paint).
 */
class Dashboard
{
    public const TYPES = ['stat', 'list', 'chart'];

    public const SIZES = ['sm', 'md', 'lg'];

    /** @var array<string, list<array{key: string, type: string, size: string, deferred: bool, resolver: Closure, permission: ?string, order: int}>> */
    private array $widgets = [];

    /** @var array<string, list<array{key: string, resolver: Closure, permission: ?string, order: int}>> */
    private array $todos = [];

    public function card(string $area, string $key, Closure $resolver, ?string $permission = null, int $order = 100): void
    {
        $this->widget($area, $key, 'stat', $resolver, $permission, $order, 'sm');
    }

    public function widget(string $area, string $key, string $type, Closure $resolver, ?string $permission = null, int $order = 100, string $size = 'md', bool $deferred = false): void
    {
        $this->widgets[$area][] = [
            'key' => $key,
            'type' => in_array($type, self::TYPES, true) ? $type : 'list',
            'size' => in_array($size, self::SIZES, true) ? $size : 'md',
            'deferred' => $deferred,
            'resolver' => $resolver,
            'permission' => $permission,
            'order' => $order,
        ];
    }

    public function todo(string $area, string $key, Closure $resolver, ?string $permission = null, int $order = 100): void
    {
        $this->todos[$area][] = ['key' => $key, 'resolver' => $resolver, 'permission' => $permission, 'order' => $order];
    }

    /**
     * Stat cards only (kept for simple dashboards).
     *
     * @return list<array<string, mixed>>
     */
    public function resolve(string $area, ?User $user, mixed ...$arguments): array
    {
        return array_values(array_filter(
            $this->resolveWidgets($area, $user, false, ...$arguments),
            fn (array $widget): bool => $widget['type'] === 'stat',
        ));
    }

    /**
     * All visible widgets in order: ['key', 'type', 'size', ...payload].
     *
     * @param  bool|null  $deferred  null = all, true = only deferred, false = only eager
     * @return list<array<string, mixed>>
     */
    public function resolveWidgets(string $area, ?User $user, ?bool $deferred = null, mixed ...$arguments): array
    {
        return array_values(collect($this->widgets[$area] ?? [])
            ->filter(fn (array $widget): bool => $deferred === null || $widget['deferred'] === $deferred)
            ->filter(fn (array $widget): bool => $this->allowed($widget['permission'], $user))
            ->sortBy('order')
            ->map(fn (array $widget): array => [
                'key' => $widget['key'],
                'type' => $widget['type'],
                'size' => $widget['size'],
                ...($widget['resolver'])($user, ...$arguments),
            ])
            ->all());
    }

    /**
     * Visible to-do items with a positive count.
     *
     * @return list<array{key: string, label: string, count: int, href?: string, tone?: string}>
     */
    public function resolveTodos(string $area, ?User $user, mixed ...$arguments): array
    {
        /** @var list<array{key: string, label: string, count: int, href?: string, tone?: string}> */
        return array_values(collect($this->todos[$area] ?? [])
            ->filter(fn (array $todo): bool => $this->allowed($todo['permission'], $user))
            ->sortBy('order')
            ->map(fn (array $todo): array => ['key' => $todo['key'], ...($todo['resolver'])($user, ...$arguments)])
            ->filter(fn (array $todo): bool => (int) ($todo['count'] ?? 0) > 0)
            ->all());
    }

    private function allowed(?string $permission, ?User $user): bool
    {
        return $permission === null || ($user?->hasPermission($permission) ?? false);
    }
}
