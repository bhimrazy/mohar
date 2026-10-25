<?php

namespace App\Support\Dashboard;

use App\Models\User;
use Closure;

/**
 * Dashboard registry. Modules contribute in bootModule(); resolvers receive (?User $user, ...$arguments) —
 * for the client area the current Client is the extra argument.
 *
 * Stat card (shortcut). Optional: 'money' => ['amount', 'currency'], 'hint', 'trend' => ['value' => '12%', 'direction' =>
 * 'up'|'down'|'flat', 'upIsGood' => bool], 'sparkline' => int[] (12 points), 'tone' => 'default'|'warning'|'danger':
 *   $dashboard->card('admin', 'billing.overdue', fn ($user) => ['label' => 'Overdue', 'value' => 3, 'href' => ..., 'icon' => 'Receipt'], 'billing.invoices.view');
 * Stat cards sending 'primary' => true form the KPI row (up to four, by order); the rest form the counter strip. A card
 * that is the same figure as a to-do with the same key is registered with mirrorsTodo: true: resolveWidgets() then skips
 * it (the dashboard shows it once, in Needs attention, without running its query), while resolve() still returns it.
 *
 * Other widgets — type + payload shape:
 *   'list'  => ['title' => 'Recent orders', 'items' => [['title', 'subtitle'?, 'href'?, 'meta'?, 'money'?, 'status'?]], 'href'? (view all),
 *               'empty'?, 'group'? + 'groupTitle'? (lists sharing a group render as tabs of one card)]
 *               An item may add 'date' + 'dateLabel' ("Renews", "Due"), shown after its subtitle, and 'action' =>
 *               ['label', 'href', 'method'? ('post'), 'confirm'? => ['title', 'description', 'confirmLabel', 'data'?]]
 *               (a button on the row: a link, or a request sent after the confirm dialog with 'data' as its body), and
 *               'toggle' => ['label' (accessible name), 'text'? (visible caption), 'href' (PUT), 'field', 'on' => bool]
 *               (a switch that saves immediately). A list may add 'emptyAction' => ['label', 'href'] and 'actions' =>
 *               [['label', 'href', 'icon'?]] (link buttons in its header; 'icon' is a nav icon name).
 *   'chart' => ['title' => 'Income', 'kind' => 'line'|'bar', 'currency'?, 'stacked'?, 'description'?, 'xFormat'? ('month'|'day'|'dayOfMonth'|'week'),
 *               'series' => [['name', 'compare'? (drawn as the neutral baseline), 'points' => [['x' => '2026-10-01', 'y' => 1234]]]],
 *               'summary'? => ['money'|'value', 'label', 'trend'?], 'ranges'? => ['12m' => ['label', 'series', 'summary'?, 'xFormat'?]], 'href'?]
 *   'breakdown' => ['title', 'description'?, 'rows' => [['key', 'label', 'code'?, 'money'|'value', 'share' (0–100)]],
 *               'notes'? => [['label', 'money']], 'notesTitle'?, 'empty'?]
 *   'notice' => ['title', 'body'?, 'tone' => 'warning'|'danger'|'info', 'actions' => [['label', 'href', 'primary'?]]]
 *               (a banner; only the client dashboard renders it, the admin dashboard ignores it)
 *   $dashboard->widget('admin', 'orders.recent', 'list', fn ($user) => [...], 'orders.orders.view', order: 30, size: 'md');
 *
 * A resolver that returns null hides its widget for this request (nothing to show, or the viewer may not see it).
 *
 * Currency variants (any money widget): 'variants' => ['all' => [...partial payload], 'USD' => [...], 'INR' => [...]]. The page's
 * reporting-currency switch merges the selected variant over the base payload; widgets without variants ignore the switch.
 *
 * "Needs attention" to-do items (aggregated into one widget, zero counts hidden). Optional 'icon' (nav icon name) and 'hint':
 *   $dashboard->todo('admin', 'support.awaiting', fn ($user) => ['label' => 'Tickets awaiting reply', 'count' => 4, 'href' => ..., 'tone' => 'warning'], 'support.tickets.view');
 *
 * System health checks (aggregated into one card, shown when permitted; a resolver that returns null has nothing to
 * check for this install and is left out):
 *   $dashboard->health('admin', 'services.provisioning', fn ($user) => ['label' => 'Provisioning', 'detail' => '146 of 148 succeeded (7 days)',
 *       'value' => '98.6%', 'tone' => 'success'|'warning'|'danger'|'neutral', 'icon' => 'Server', 'href'?], 'services.module_log.view');
 *
 * Sizes: 'sm' (1/4 row), 'md' (1/2), 'lg' (full). Heavy widgets set $deferred = true (loaded after first paint).
 */
class Dashboard
{
    public const TYPES = ['stat', 'list', 'chart', 'breakdown', 'notice'];

    public const SIZES = ['sm', 'md', 'lg'];

    /** @var array<string, list<array{key: string, type: string, size: string, deferred: bool, mirrorsTodo: bool, resolver: Closure, permission: ?string, order: int}>> */
    private array $widgets = [];

    /** @var array<string, list<array{key: string, resolver: Closure, permission: ?string, order: int}>> */
    private array $todos = [];

    /** @var array<string, list<array{key: string, resolver: Closure, permission: ?string, order: int}>> */
    private array $health = [];

    public function card(string $area, string $key, Closure $resolver, ?string $permission = null, int $order = 100, bool $mirrorsTodo = false): void
    {
        $this->widget($area, $key, 'stat', $resolver, $permission, $order, 'sm', mirrorsTodo: $mirrorsTodo);
    }

    public function widget(string $area, string $key, string $type, Closure $resolver, ?string $permission = null, int $order = 100, string $size = 'md', bool $deferred = false, bool $mirrorsTodo = false): void
    {
        $this->widgets[$area][] = [
            'key' => $key,
            'type' => in_array($type, self::TYPES, true) ? $type : 'list',
            'size' => in_array($size, self::SIZES, true) ? $size : 'md',
            'deferred' => $deferred,
            'mirrorsTodo' => $mirrorsTodo,
            'resolver' => $resolver,
            'permission' => $permission,
            'order' => $order,
        ];
    }

    public function todo(string $area, string $key, Closure $resolver, ?string $permission = null, int $order = 100): void
    {
        $this->todos[$area][] = ['key' => $key, 'resolver' => $resolver, 'permission' => $permission, 'order' => $order];
    }

    public function health(string $area, string $key, Closure $resolver, ?string $permission = null, int $order = 100): void
    {
        $this->health[$area][] = ['key' => $key, 'resolver' => $resolver, 'permission' => $permission, 'order' => $order];
    }

    /**
     * Stat cards only, including cards that mirror a to-do; those carry 'mirrorsTodo' => true. The dashboards
     * themselves use resolveWidgets(); this serves callers that need just the counters.
     *
     * @return list<array<string, mixed>>
     */
    public function resolve(string $area, ?User $user, mixed ...$arguments): array
    {
        return array_values(array_filter(
            $this->resolveSet($area, $user, false, true, ...$arguments),
            fn (array $widget): bool => $widget['type'] === 'stat',
        ));
    }

    /**
     * All visible widgets in order: ['key', 'type', 'size', ...payload]. Cards that mirror a to-do are skipped
     * without running their resolvers.
     *
     * @param  bool|null  $deferred  null = all, true = only deferred, false = only eager
     * @return list<array<string, mixed>>
     */
    public function resolveWidgets(string $area, ?User $user, ?bool $deferred = null, mixed ...$arguments): array
    {
        return $this->resolveSet($area, $user, $deferred, false, ...$arguments);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveSet(string $area, ?User $user, ?bool $deferred, bool $withMirrors, mixed ...$arguments): array
    {
        return array_values(collect($this->widgets[$area] ?? [])
            ->filter(fn (array $widget): bool => $deferred === null || $widget['deferred'] === $deferred)
            ->filter(fn (array $widget): bool => $withMirrors || ! $widget['mirrorsTodo'])
            ->filter(fn (array $widget): bool => $this->allowed($widget['permission'], $user))
            ->sortBy('order')
            ->map(function (array $widget) use ($user, $arguments): ?array {
                $payload = ($widget['resolver'])($user, ...$arguments);

                return $payload === null ? null : [
                    'key' => $widget['key'],
                    'type' => $widget['type'],
                    'size' => $widget['size'],
                    ...($widget['mirrorsTodo'] ? ['mirrorsTodo' => true] : []),
                    ...$payload,
                ];
            })
            ->filter()
            ->all());
    }

    /**
     * Visible to-do items with a positive count.
     *
     * @return list<array{key: string, label: string, count: int, href?: string, tone?: string, icon?: string, hint?: string}>
     */
    public function resolveTodos(string $area, ?User $user, mixed ...$arguments): array
    {
        /** @var list<array{key: string, label: string, count: int, href?: string, tone?: string, icon?: string, hint?: string}> */
        return array_values(collect($this->todos[$area] ?? [])
            ->filter(fn (array $todo): bool => $this->allowed($todo['permission'], $user))
            ->sortBy('order')
            ->map(fn (array $todo): array => ['key' => $todo['key'], ...($todo['resolver'])($user, ...$arguments)])
            ->filter(fn (array $todo): bool => (int) ($todo['count'] ?? 0) > 0)
            ->all());
    }

    /**
     * Visible system health checks in order.
     *
     * @return list<array{key: string, label: string, detail?: string, value: string, tone: string, icon?: string, href?: string}>
     */
    public function resolveHealth(string $area, ?User $user, mixed ...$arguments): array
    {
        /** @var list<array{key: string, label: string, detail?: string, value: string, tone: string, icon?: string, href?: string}> */
        return array_values(collect($this->health[$area] ?? [])
            ->filter(fn (array $check): bool => $this->allowed($check['permission'], $user))
            ->sortBy('order')
            ->map(fn (array $check): ?array => ($result = ($check['resolver'])($user, ...$arguments)) === null ? null : ['key' => $check['key'], ...$result])
            ->filter()
            ->all());
    }

    private function allowed(?string $permission, ?User $user): bool
    {
        return $permission === null || ($user?->hasPermission($permission) ?? false);
    }
}
