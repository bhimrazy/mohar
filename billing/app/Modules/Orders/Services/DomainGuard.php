<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Duplicate-domain protection (orders.block_duplicate_domains): a hosting domain may not be ordered
 * again while a service created by an order still uses it.
 */
class DomainGuard
{
    public function isAttachedToService(string $domain): bool
    {
        return OrderItem::query()
            ->where('item_type', OrderItemType::Service)
            ->where('domain', strtolower($domain))
            ->whereIn('status', [OrderItemStatus::Pending, OrderItemStatus::Active])
            ->whereHas('order', fn (Builder $order) => $order->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Fraud]))
            ->exists();
    }

    /**
     * Country registries that register names only under these second levels (verified 2026-10-20 at
     * register.com.np/np-ccTLDs, the .np registry run by Mercantile Communications per IANA).
     */
    public const SECOND_LEVELS = [
        'np' => ['com', 'coop', 'edu', 'gov', 'info', 'mil', 'name', 'net', 'org'],
    ];

    /**
     * Why an existing domain cannot exist under its registry's rules (e.g. "asha.np"), or null.
     */
    public static function registryLevelError(string $domain): ?string
    {
        $labels = explode('.', strtolower(trim($domain, '.')));
        $top = end($labels);
        $levels = self::SECOND_LEVELS[$top] ?? null;

        if ($levels === null || (count($labels) >= 3 && in_array($labels[count($labels) - 2], $levels, true))) {
            return null;
        }

        return __('Enter your full .:tld name, for example yourname.com.:tld. Names are registered under :levels.', [
            'tld' => $top,
            'levels' => implode(', ', array_map(fn (string $level): string => ".{$level}.{$top}", $levels)),
        ]);
    }

    public static function isValidName(string $domain): bool
    {
        return (bool) preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/i', $domain);
    }
}
