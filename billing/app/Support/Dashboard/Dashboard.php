<?php

namespace App\Support\Dashboard;

use App\Models\User;
use Closure;

/**
 * Dashboard stat-card registry. Modules register cards in bootModule():
 *
 *   app(Dashboard::class)->card('admin', 'billing.overdue', fn (User $user) => [
 *       'label' => 'Overdue invoices', 'value' => 3, 'href' => route('admin.billing.invoices.index', ['status' => 'overdue']), 'icon' => 'AlertTriangle',
 *   ], permission: 'billing.invoices.view', order: 20);
 *
 * For the client area the resolver receives the current Client as second argument.
 */
class Dashboard
{
    /** @var array<string, list<array{key: string, resolver: Closure, permission: ?string, order: int}>> */
    private array $cards = ['admin' => [], 'client' => []];

    public function card(string $area, string $key, Closure $resolver, ?string $permission = null, int $order = 100): void
    {
        $this->cards[$area][] = ['key' => $key, 'resolver' => $resolver, 'permission' => $permission, 'order' => $order];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resolve(string $area, ?User $user, mixed ...$arguments): array
    {
        return array_values(collect($this->cards[$area] ?? [])
            ->filter(fn (array $card): bool => $card['permission'] === null || ($user?->hasPermission($card['permission']) ?? false))
            ->sortBy('order')
            ->map(fn (array $card): array => ['key' => $card['key'], ...($card['resolver'])($user, ...$arguments)])
            ->all());
    }
}
