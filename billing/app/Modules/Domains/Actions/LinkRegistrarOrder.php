<?php

namespace App\Modules\Domains\Actions;

use App\Contracts\Domains\Registrar\LinksExistingOrders;
use App\Data\Domains\Registrar\LinkedOrder;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Domains\Support\DomainRegistrarLock;
use App\Modules\Domains\Support\RemoteIds;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Domains\RegistrarException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Staff link an order that already exists at the registrar to a domain the host holds without a recorded order id (an
 * imported domain, or one staff set Active). Sync never does this on its own: an order found by searching for a name
 * is not proof of ownership (registrars.md §3). So the link is explicit and checked:
 *
 *  - the registrar looks the order up by the id staff typed, and the driver refuses unless it is in our account, for
 *    exactly this name, still holding it, and under the customer this host uses for the client;
 *  - no other domain row (a deleted record counts) already holds that order id;
 *  - nothing is recorded while a registrar action runs for the domain, and the domain is read again under that lock;
 *  - the registrar's answer must carry the order id that was typed.
 *
 * Locks (ADR 0021 §2, §3): the order's cache lock and the domain's registrar lock span the registrar call; both last
 * longer than it can run. The record is written in one transaction that first locks, in id order, every row of this
 * registrar with the same name (a deleted one too), because only those can compete for the order: the driver accepts
 * an order of exactly the domain's name. The check "no other row holds the order" is repeated under that lock.
 *
 * The record and its audit entry are written together. Repeating the same link changes nothing and writes no second
 * entry; a different id for a linked domain is refused. One sync follows, so the expiry and status come from the order.
 */
class LinkRegistrarOrder
{
    /** Statuses of a domain that is, or was, live at the registrar; a pending one is settled by its own submission. */
    private const LINKABLE = [DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption, DomainStatus::Expired];

    public function __construct(
        private RegistrarGateway $gateway,
        private RegistrarDriverRegistry $drivers,
        private ActivityLogger $activity,
        private SyncDomain $sync,
    ) {}

    /**
     * @return array{linked: bool, sync: array{changes: list<array<string, mixed>>, error: string|null}}
     *
     * @throws RegistrarException with a plain message for every refusal
     */
    public function handle(Domain $domain, string $orderId, ?int $staffId = null): array
    {
        $orderId = trim($orderId);
        $this->assertLinkable($domain);

        $held = $this->heldOrderId($domain);

        if ($held !== null) {
            if ($held !== $orderId) {
                throw new RegistrarException("{$domain->name} is already linked to order {$held}. Linking another order is not offered here; check the order in the registrar's panel.");
            }

            return ['linked' => false, 'sync' => $this->sync->handle($domain, staffId: $staffId)];
        }

        $order = Cache::lock('domains:link-order:'.$domain->registrar_key.':'.$orderId, DomainRegistrarLock::TTL);

        if (! $order->get()) {
            throw new RegistrarException("Order {$orderId} is being linked right now. Try again in a minute.");
        }

        try {
            $linked = $this->linkLocked($domain, $orderId, $staffId);
        } finally {
            $order->release();
        }

        return ['linked' => $linked, 'sync' => $this->sync->handle($domain, staffId: $staffId)];
    }

    private function linkLocked(Domain $domain, string $orderId, ?int $staffId): bool
    {
        $lock = DomainRegistrarLock::for($domain->id);

        if (! $lock->get()) {
            throw new RegistrarException('Another registrar action is running for this domain. Try again in a minute.');
        }

        try {
            $domain->refresh();
            $this->assertLinkable($domain);

            if ($this->heldOrderId($domain) !== null) {
                return false;
            }

            $this->assertUnused($domain, $orderId);

            $found = $this->gateway->forDomain(
                $domain,
                RegistrarDriverRegistry::LINK_ORDER,
                LinksExistingOrders::class,
                fn (LinksExistingOrders $registrar, RegistrarConfig $config): LinkedOrder => $registrar->lookupOrder($config, $domain->toRef(), $orderId),
                ['order_id' => $orderId],
                $staffId,
            );

            if ((string) ($found->remote['order_id'] ?? '') !== $orderId) {
                throw new RegistrarException('The registrar answered with a different order than order '.$orderId.'. Nothing was linked.');
            }

            DB::transaction(function () use ($domain, $orderId, $found): void {
                $row = Domain::withTrashed()
                    ->where('registrar_key', $domain->registrar_key)
                    ->where('name', $domain->name)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->firstWhere('id', $domain->id) ?? throw new RegistrarException("{$domain->name} is no longer available to link. Reload the page.");
                $this->assertLinkable($row);
                $this->assertUnused($row, $orderId);

                if ($this->heldOrderId($row) !== null) {
                    throw new RegistrarException("{$row->name} was linked to another order a moment ago. Reload the page.");
                }

                $row->forceFill(['registrar_meta' => RemoteIds::merge($row->registrar_meta, $found->remote)])->save();
                $this->activity->log("Linked {$row->registrar_key} order {$orderId} to {$row->name}", $row, $row->client_id, ['order_id' => $orderId, 'registrar' => $row->registrar_key], 'domain.order_linked');
            });

            return true;
        } finally {
            $lock->release();
        }
    }

    private function assertLinkable(Domain $domain): void
    {
        if ($domain->registrar_key === null || ! $this->drivers->supports($domain->registrar_key, RegistrarDriverRegistry::LINK_ORDER)) {
            throw new RegistrarException('This registrar does not support linking an existing order.');
        }

        if ($domain->loadMissing('tldModel')->tldModel->registered_by_client || Tld::registryLevelHint($domain->name) !== null) {
            throw new RegistrarException(".np domains are registered by the client at the registry, not through a registrar, so {$domain->name} has no registrar order to link.");
        }

        if (! in_array($domain->status, self::LINKABLE, true)) {
            throw new RegistrarException("A {$domain->status->label()} domain cannot be linked to an order. Only active, grace, redemption and expired domains can.");
        }
    }

    private function heldOrderId(Domain $domain): ?string
    {
        $id = $domain->registrar_meta['remote']['order_id'] ?? null;

        return is_int($id) || (is_string($id) && $id !== '') ? (string) $id : null;
    }

    /**
     * No other domain row of this registrar holds the order id. A deleted record counts: it can be restored.
     */
    private function assertUnused(Domain $domain, string $orderId): void
    {
        $other = Domain::withTrashed()
            ->where('registrar_key', $domain->registrar_key)
            ->where('id', '!=', $domain->id)
            ->whereIn('registrar_meta->remote->order_id', [(int) $orderId, $orderId])
            ->first(['id', 'name']);

        if ($other !== null) {
            throw new RegistrarException("Order {$orderId} is already linked to {$other->name}. One order belongs to one domain; nothing was changed.");
        }
    }
}
