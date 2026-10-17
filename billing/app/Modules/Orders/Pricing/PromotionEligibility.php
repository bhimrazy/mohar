<?php

namespace App\Modules\Orders\Pricing;

use App\Modules\Core\Models\Client;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Models\PromotionRedemption;
use App\Modules\Orders\Services\CompanyClock;
use App\Modules\Orders\Services\CurrencyConverter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a promotion may be used for a cart (orders.md §5 eligibility). Returns the customer-facing
 * reason when it may not. Client-specific rules are checked once the client is known; a guest is
 * treated as a new client.
 */
class PromotionEligibility
{
    public function __construct(
        private CompanyClock $clock,
        private CurrencyConverter $converter,
    ) {}

    /**
     * @param  list<PricedLine>  $lines  valid cart lines
     */
    public function check(Promotion $promotion, array $lines, int $subtotal, string $currency, ?Client $client): ?string
    {
        if ($promotion->expired_at !== null) {
            return __('This promotion code has expired.');
        }

        $today = $this->clock->now()->toDateString();

        if ($promotion->starts_on !== null && $today < $promotion->starts_on->toDateString()) {
            return __('This promotion code is not active yet.');
        }

        if ($promotion->expires_on !== null && $today > $promotion->expires_on->toDateString()) {
            return __('This promotion code has expired.');
        }

        if ($promotion->max_uses > 0 && $promotion->uses >= $promotion->max_uses) {
            return __('Usage limit reached: this promotion code can no longer be used.');
        }

        if ($error = $this->clientError($promotion, $client)) {
            return $error;
        }

        if (! $this->requirementsMet($promotion, $lines, $client)) {
            return __('This promotion code requires another product in your cart.');
        }

        if ($promotion->min_cart_amount !== null && $subtotal < $this->converter->fromDefault($promotion->min_cart_amount, $currency)) {
            return __('Your cart total is below the minimum for this promotion code.');
        }

        return null;
    }

    private function clientError(Promotion $promotion, ?Client $client): ?string
    {
        if ($promotion->existing_clients_only && ($client === null || ! $this->hasActiveService($client))) {
            return __('This promotion code is only for existing customers with an active service.');
        }

        if ($client === null) {
            return null;
        }

        if ($promotion->new_clients_only && $this->hasPriorOrders($client)) {
            return __('This promotion code is only for new customers.');
        }

        if ($promotion->once_per_client && $this->hasRedeemed($promotion, $client)) {
            return __('You have already used this promotion code.');
        }

        return null;
    }

    /**
     * @param  list<PricedLine>  $lines
     */
    private function requirementsMet(Promotion $promotion, array $lines, ?Client $client): bool
    {
        $productIds = array_map(intval(...), $promotion->requires['product_ids'] ?? []);
        $cycles = $promotion->requires['cycles'] ?? [];
        $tlds = array_map(fn (string $tld): string => '.'.ltrim(strtolower($tld), '.'), $promotion->requires['tlds'] ?? []);

        if ($productIds === [] && $tlds === []) {
            return true;
        }

        foreach ($lines as $line) {
            $productMatch = $line->kind === 'product' && in_array($line->productId, $productIds, true)
                && ($cycles === [] || in_array($line->cycle?->value, $cycles, true));
            $tldMatch = $line->kind === 'domain' && in_array($line->tld, $tlds, true);

            if ($productMatch || $tldMatch) {
                return true;
            }
        }

        return $promotion->requires_existing_ok && $client !== null && $productIds !== [] && $this->ownedItems($client)
            ->whereIn('product_id', $productIds)
            ->exists();
    }

    public function hasPriorOrders(Client $client): bool
    {
        return Order::query()
            ->where('client_id', $client->id)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->exists();
    }

    public function hasActiveService(Client $client): bool
    {
        return $this->ownedItems($client)->exists();
    }

    private function hasRedeemed(Promotion $promotion, Client $client): bool
    {
        return PromotionRedemption::query()
            ->where('promotion_id', $promotion->id)
            ->where('client_id', $client->id)
            ->whereNull('released_at')
            ->exists();
    }

    /**
     * Active services the client obtained through orders.
     *
     * @return Builder<OrderItem>
     */
    private function ownedItems(Client $client): Builder
    {
        return OrderItem::query()
            ->where('status', OrderItemStatus::Active)
            ->where('item_type', 'service')
            ->whereHas('order', fn (Builder $order) => $order->where('client_id', $client->id));
    }
}
