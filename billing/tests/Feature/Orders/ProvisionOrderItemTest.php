<?php

use App\Data\Services\ProvisionResult;
use App\Events\Services\ProvisioningFailed;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Actions\AcceptOrder;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Jobs\ProvisionOrderItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\ItemProvisioner;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Support\Queues;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Orders\OrdersFakes;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    Currency::factory()->create(['code' => 'NPR']);
});

function provisionOrderItemJob(OrderItem $item): ProvisionOrderItem
{
    $job = (new ProvisionOrderItem($item->id))->withFakeQueueInteractions();
    $job->handle(app(ItemProvisioner::class));

    return $job;
}

function onOrderItem(array $attributes = []): OrderItem
{
    return OrderItem::factory()->for(Order::factory()->state(['currency' => 'NPR']))->create(['auto_setup' => 'order', ...$attributes]);
}

describe('with a faked service registry', function () {
    beforeEach(function () {
        $this->fakes = OrdersFakes::bind();
    });

    it('provisions a pending item when its lock is free', function () {
        $item = onOrderItem();

        $job = provisionOrderItemJob($item);

        expect($this->fakes->services->provisioned)->toBe([$item->item_id])
            ->and($item->refresh()->status)->toBe(OrderItemStatus::Active);
        $job->assertNotReleased();
        $job->assertNotFailed();
    });

    it('releases the job while another process holds the lock, then provisions once it is free', function () {
        $item = onOrderItem();
        $lock = Cache::lock(ItemProvisioner::lockKey($item->id), ItemProvisioner::LOCK_SECONDS);
        expect($lock->get())->toBeTrue();

        provisionOrderItemJob($item)->assertReleased(ProvisionOrderItem::BUSY_RELEASE_SECONDS);
        expect($this->fakes->services->provisioned)->toBe([])
            ->and($item->refresh()->status)->toBe(OrderItemStatus::Pending);

        $lock->release();
        provisionOrderItemJob($item)->assertNotReleased();

        expect($this->fakes->services->provisioned)->toBe([$item->item_id])
            ->and($item->refresh()->status)->toBe(OrderItemStatus::Active);
    });

    it('lets an exception fail the attempt so the queue retries it, and does not retry a handled failure', function () {
        $item = onOrderItem();
        $this->fakes->services->outcomes[$item->item_id] = new RuntimeException('database went away');

        expect(fn () => provisionOrderItemJob($item))->toThrow(RuntimeException::class, 'database went away')
            ->and($item->refresh()->status)->toBe(OrderItemStatus::Pending)
            ->and(Cache::lock(ItemProvisioner::lockKey($item->id), 1)->get())->toBeTrue();

        // A driver failure is answered by Services (failure item, its own retries): the job ends.
        $failed = onOrderItem();
        $this->fakes->services->outcomes[$failed->item_id] = new ProvisionResult(false, 'Disk quota exceeded');
        provisionOrderItemJob($failed)->assertNotReleased();
        expect($failed->refresh()->status)->toBe(OrderItemStatus::Pending);
    });

    it('skips a busy item on staff accept without reporting a failure', function () {
        $item = onOrderItem(['auto_setup' => 'accept']);
        Cache::lock(ItemProvisioner::lockKey($item->id), ItemProvisioner::LOCK_SECONDS)->get();

        $result = app(AcceptOrder::class)->handle($item->order, [$item->id]);

        expect($result)->toBe(['failures' => [], 'queued' => []])
            ->and($this->fakes->services->provisioned)->toBe([]);
    });

    it('tells staff when the job runs out of tries and leaves the item pending for a manual create', function () {
        Event::fake([ProvisioningFailed::class]);
        $item = onOrderItem();

        (new ProvisionOrderItem($item->id))->failed(new MaxAttemptsExceededException('ProvisionOrderItem has been attempted too many times.'));

        expect($item->refresh()->status)->toBe(OrderItemStatus::Pending)
            ->and(ActivityLog::query()->where('subject_id', $item->order_id)->value('description'))->toBe("Automatic setup of {$item->description} on order {$item->order->number} did not run");
        Event::assertDispatched(ProvisioningFailed::class, fn (ProvisioningFailed $event) => $event->serviceId === $item->item_id
            && $event->action === 'create' && ! $event->outcomeUnknown && str_contains($event->error, 'another process kept the item busy'));

        // An item provisioned in the meantime needs nothing.
        $done = onOrderItem(['status' => OrderItemStatus::Active]);
        (new ProvisionOrderItem($done->id))->failed(new RuntimeException('x'));
        Event::assertDispatchedTimes(ProvisioningFailed::class, 1);
    });

    it('keeps exception text out of the failure activity and event', function () {
        Event::fake([ProvisioningFailed::class]);
        $item = onOrderItem();

        (new ProvisionOrderItem($item->id))->failed(new RuntimeException('panel said token=tk-55ab90'));

        expect(ActivityLog::query()->where('subject_id', $item->order_id)->sole()->properties)
            ->toBe(['order_item_id' => $item->id, 'reason' => 'RuntimeException: the job did not finish']);
        Event::assertDispatched(ProvisioningFailed::class, fn (ProvisioningFailed $event) => $event->error === 'Automatic setup did not run (RuntimeException: the job did not finish); run create from the order.');
    });

    it('runs on the provisioning queue and times out before the lock and the queue redelivery expire', function () {
        $job = new ProvisionOrderItem(1);

        expect($job->queue)->toBe(Queues::PROVISIONING)
            ->and($job->maxExceptions)->toBe(3)
            ->and($job->timeout)->toBeLessThan(ItemProvisioner::LOCK_SECONDS)
            ->and(ItemProvisioner::LOCK_SECONDS)->toBeLessThan((int) config('queue.connections.database.retry_after'))
            ->and($job->timeout)->toBeLessThan(Queues::PROVIDER_POOL_TIMEOUT_SECONDS)
            // Enough releases to outlast a full lock TTL held by a dead worker.
            ->and(($job->tries - $job->maxExceptions) * ProvisionOrderItem::BUSY_RELEASE_SECONDS)->toBeGreaterThan(ItemProvisioner::LOCK_SECONDS);
    });
});

it('recovers from a dead worker’s stale lock without running create a second time', function () {
    Event::fake([ProvisioningFailed::class]);
    $kit = ServicesTestKit::bind();
    $kit->priceBook->addProduct(11, 'Starter');
    $service = Service::factory()->pending()->create(['product_id' => 11, 'server_id' => Server::factory()->create()->id]);
    $item = onOrderItem(['item_id' => $service->id, 'product_id' => 11]);

    // The worker took the item lock and logged its create as running, then died before the driver answered.
    Cache::lock(ItemProvisioner::lockKey($item->id), ItemProvisioner::LOCK_SECONDS)->get();
    ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'log', 'command' => 'create', 'initiator' => 'system', 'success' => false, 'status' => ModuleCommand::RUNNING]);

    $this->travel(ItemProvisioner::LOCK_SECONDS - 60)->seconds();
    provisionOrderItemJob($item)->assertReleased(ProvisionOrderItem::BUSY_RELEASE_SECONDS);

    $this->travel(ProvisionOrderItem::BUSY_RELEASE_SECONDS + 1)->seconds();
    provisionOrderItemJob($item)->assertNotReleased();

    expect($kit->driver->callsFor('create'))->toBeEmpty()
        ->and(ModuleCommand::query()->where('service_id', $service->id)->sole()->status)->toBe(ModuleCommand::UNKNOWN)
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->value('kind'))->toBe(ModuleQueueItem::KIND_VERIFY)
        ->and($item->refresh()->status)->toBe(OrderItemStatus::Pending);
    Event::assertDispatched(ProvisioningFailed::class, fn (ProvisioningFailed $event) => $event->outcomeUnknown);
});
