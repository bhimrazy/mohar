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
     * The form every order path stores: trimmed, lower case, no trailing dot, Unicode labels as punycode. A name
     * that cannot be converted is returned as typed (lower case) so isValidName() refuses it.
     */
    public static function normalise(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        if ($domain === '' || ! preg_match('/[^\x00-\x7F]/', $domain) || ! function_exists('idn_to_ascii')) {
            return $domain;
        }

        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? $domain : strtolower($ascii);
    }

    public static function isValidName(string $domain): bool
    {
        return (bool) preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/i', $domain);
    }
}
