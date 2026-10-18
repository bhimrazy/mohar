<?php

namespace App\Modules\Orders\Support\Demo;

use App\Contracts\Services\ServiceRegistry;
use App\Data\Services\AddonDraft;
use App\Data\Services\ProvisionResult;
use App\Data\Services\ServiceDraft;
use Illuminate\Support\Facades\Cache;

/**
 * LOCAL DEMO ONLY: hands out ids and "provisions" instantly until the Services module is merged.
 */
class DemoServices implements ServiceRegistry
{
    public function createPending(ServiceDraft $draft): int
    {
        return self::nextId('orders.demo.service_id');
    }

    public function createPendingAddon(AddonDraft $draft): int
    {
        return self::nextId('orders.demo.addon_id');
    }

    public function provision(int $serviceId): ProvisionResult
    {
        return new ProvisionResult(true, 'Provisioned on the demo server.');
    }

    public function cancelPending(int $serviceId, string $status = 'cancelled'): void {}

    /**
     * Sequential demo id (Cache::increment returns false for a missing key on some stores).
     */
    private static function nextId(string $key): int
    {
        Cache::add($key, 0);

        return (int) Cache::increment($key);
    }
}
