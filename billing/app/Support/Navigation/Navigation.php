<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar navigation registry. Modules add items in their provider's bootModule():
 *
 *   app(Navigation::class)->add('admin', new NavItem('Invoices', 'admin.billing.invoices.index', 'FileText', 'Billing', 10, 'billing.invoices.view'));
 *
 * @api
 */
class Navigation
{
    /**
     * "admin-client" items are links on the admin client profile; their route receives ['client' => id].
     * "admin-create" items are quick-create actions (New client, New invoice, ...) in the admin header.
     */
    public const AREAS = ['admin', 'client', 'admin-client', 'admin-create'];

    /** Fixed ordering of sidebar groups; unknown groups go last. */
    public const GROUP_ORDER = ['General', 'Clients', 'Orders', 'Billing', 'Services', 'Domains', 'Support', 'Marketing', 'Reports', 'Setup'];

    /** Client area: what the customer owns comes first, then billing, ordering and help. */
    public const CLIENT_GROUP_ORDER = ['General', 'Services', 'Domains', 'Billing', 'Orders', 'Support', 'Setup'];

    /** @var array<string, list<NavItem>> */
    private array $items = ['admin' => [], 'client' => [], 'admin-client' => [], 'admin-create' => []];

    public function add(string $area, NavItem $item): void
    {
        $this->items[$area][] = $item;
    }

    /**
     * @return list<array{title: string, items: list<array{title: string, href: string, icon: string, active: bool, badge: int|null}>}>
     */
    public function forUser(string $area, ?User $user): array
    {
        $current = Route::currentRouteName() ?? '';
        $groupOrder = $area === 'client' ? self::CLIENT_GROUP_ORDER : self::GROUP_ORDER;

        $visible = collect($this->items[$area] ?? [])
            ->filter(fn (NavItem $item): bool => Route::has($item->route))
            ->filter(fn (NavItem $item): bool => $item->permission === null || ($user?->hasPermission($item->permission) ?? false))
            ->sortBy('order');

        return array_values($visible
            ->groupBy('group')
            ->sortBy(fn ($items, string $group): int => ($index = array_search($group, $groupOrder, true)) === false ? 999 : $index)
            ->map(fn ($items, string $group): array => [
                'title' => $group,
                'items' => array_values($items->map(fn (NavItem $item): array => [
                    'title' => $item->title,
                    'href' => route($item->route),
                    'icon' => $item->icon,
                    'active' => $current === $item->route || ($item->activePattern !== null && fnmatch($item->activePattern, $current)),
                    'badge' => $item->badge !== null ? ($item->badge)($user) : null,
                ])->all()),
            ])
            ->all());
    }

    /**
     * Links shown on the admin client profile, e.g. "Invoices" -> admin.billing.invoices.index?client=5.
     *
     * @return list<array{title: string, href: string, icon: string}>
     */
    public function clientLinks(int $clientId, ?User $user): array
    {
        return array_values(collect($this->items['admin-client'])
            ->filter(fn (NavItem $item): bool => Route::has($item->route))
            ->filter(fn (NavItem $item): bool => $item->permission === null || ($user?->hasPermission($item->permission) ?? false))
            ->sortBy('order')
            ->map(fn (NavItem $item): array => [
                'title' => $item->title,
                'href' => route($item->route, ['client' => $clientId]),
                'icon' => $item->icon,
            ])
            ->all());
    }

    /**
     * Quick-create actions visible to the user.
     *
     * @return list<array{title: string, href: string, icon: string}>
     */
    public function quickActions(?User $user): array
    {
        return array_values(collect($this->items['admin-create'])
            ->filter(fn (NavItem $item): bool => Route::has($item->route))
            ->filter(fn (NavItem $item): bool => $item->permission === null || ($user?->hasPermission($item->permission) ?? false))
            ->sortBy('order')
            ->map(fn (NavItem $item): array => ['title' => $item->title, 'href' => route($item->route), 'icon' => $item->icon])
            ->all());
    }
}
