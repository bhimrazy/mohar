<?php

namespace App\Modules\Orders\Services;

use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Services\ServiceRegistry;
use App\Data\Services\ProvisionResult;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Exceptions\ItemBeingProvisioned;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Support\Facades\Cache;

/**
 * Runs "create" for one order item at most once: the item is claimed under a lock and re-read, and only
 * an item that is still pending is sent to Services/Domains (staff accept, bulk accept, auto-accept and
 * the on-order job can all race for the same item).
 */
class ItemProvisioner
{
    /**
     * Lock TTL: longer than any holder runs (the job's 180 s timeout, a page's synchronous create), shorter than the
     * queue's 240 s retry_after, so a job redelivered after its worker died finds the lock already expired.
     */
    public const LOCK_SECONDS = 200;

    public function __construct(
        private ServiceRegistry $services,
        private DomainRegistry $domains,
    ) {}

    /**
     * @return ProvisionResult|null null when the item was skipped (not pending or an addon)
     *
     * @throws ItemBeingProvisioned when another process holds the item's lock
     */
    public function provision(int $orderItemId): ?ProvisionResult
    {
        $result = Cache::lock(self::lockKey($orderItemId), self::LOCK_SECONDS)->get(function () use ($orderItemId): ?ProvisionResult {
            $item = OrderItem::query()->find($orderItemId);

            if ($item === null || $item->item_id === null || $item->status !== OrderItemStatus::Pending || $item->item_type === OrderItemType::Addon) {
                return null;
            }

            $result = $item->item_type === OrderItemType::Domain
                ? $this->domains->process($item->item_id)
                : $this->services->provision($item->item_id);

            if ($result->success) {
                $item->update(['status' => OrderItemStatus::Active]);
            }

            return $result;
        });

        if ($result === false) {
            throw new ItemBeingProvisioned($orderItemId);
        }

        return $result instanceof ProvisionResult ? $result : null;
    }

    public static function lockKey(int $orderItemId): string
    {
        return "orders.provision.item.{$orderItemId}";
    }
}
