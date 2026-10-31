<?php

namespace App\Modules\Orders\Jobs;

use App\Events\Services\ProvisioningFailed;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderItemType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\ItemBeingProvisioned;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\ItemProvisioner;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * Runs "create" for a service ordered with auto-setup on order. Skips items that are no longer pending
 * or whose order has been flagged in the meantime.
 *
 * - Item lock held (another create is running, or a dead worker's lock has not expired): released and tried again, so
 *   the item never stays pending behind a job that ended as a success. Releases use tries, not maxExceptions.
 * - Running create twice is safe: Services refuses a create while an earlier attempt is unfinished or of unknown
 *   outcome (module_commands) and opens a "verify" item instead.
 * - timeout (180 s) < item lock TTL (200 s) < the queue's retry_after (240 s).
 * - Out of tries, the item stays pending for staff to run create, with an activity entry and ProvisioningFailed (the
 *   staff mail every provisioning failure sends), never silently.
 */
class ProvisionOrderItem implements ShouldQueue
{
    use Queueable;

    /** Seconds before a job that found the item lock held runs again. */
    public const BUSY_RELEASE_SECONDS = 60;

    /** Enough releases to outlast one full lock TTL, with room for an exception's retries. */
    public int $tries = 8;

    public int $maxExceptions = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $orderItemId)
    {
        $this->onQueue(Queues::PROVISIONING);
    }

    public function handle(ItemProvisioner $provisioner): void
    {
        $item = OrderItem::query()->with('order')->find($this->orderItemId);

        if ($item === null || in_array($item->order->status, [OrderStatus::Fraud, OrderStatus::Cancelled], true)) {
            return;
        }

        try {
            $provisioner->provision($item->id);
        } catch (ItemBeingProvisioned) {
            $this->release(self::BUSY_RELEASE_SECONDS);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $item = OrderItem::query()->with('order')->find($this->orderItemId);

        if ($item === null || $item->status !== OrderItemStatus::Pending) {
            return;
        }

        // The class only: an exception message may carry provider text.
        $reason = $exception instanceof MaxAttemptsExceededException
            ? 'another process kept the item busy'
            : ($exception === null ? 'the job did not finish' : class_basename($exception).': the job did not finish');
        $error = "Automatic setup did not run ({$reason}); run create from the order.";

        app(ActivityLogger::class)->log("Automatic setup of {$item->description} on order {$item->order->number} did not run", $item->order, $item->order->client_id, ['order_item_id' => $item->id, 'reason' => $reason]);

        if ($item->item_type === OrderItemType::Service && $item->item_id !== null) {
            ProvisioningFailed::dispatch($item->item_id, $item->order->client_id, 'create', $error);
        }
    }
}
