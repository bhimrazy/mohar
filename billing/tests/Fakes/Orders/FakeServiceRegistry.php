<?php

namespace Tests\Fakes\Orders;

use App\Contracts\Services\ServiceRegistry;
use App\Data\Services\AddonDraft;
use App\Data\Services\ProvisionResult;
use App\Data\Services\ServiceDraft;
use RuntimeException;

class FakeServiceRegistry implements ServiceRegistry
{
    /** @var array<int, ServiceDraft> */
    public array $services = [];

    /** @var array<int, AddonDraft> */
    public array $addons = [];

    /** @var list<int> */
    public array $provisioned = [];

    /** @var array<int, string> */
    public array $cancelled = [];

    /** @var array<int, ProvisionResult|RuntimeException> service id => forced outcome */
    public array $outcomes = [];

    private int $nextId = 100;

    private int $nextAddonId = 300;

    public function createPending(ServiceDraft $draft): int
    {
        $id = $this->nextId++;
        $this->services[$id] = $draft;

        return $id;
    }

    public function createPendingAddon(AddonDraft $draft): int
    {
        $id = $this->nextAddonId++;
        $this->addons[$id] = $draft;

        return $id;
    }

    public function provision(int $serviceId): ProvisionResult
    {
        $this->provisioned[] = $serviceId;
        $outcome = $this->outcomes[$serviceId] ?? new ProvisionResult(true);

        if ($outcome instanceof RuntimeException) {
            throw $outcome;
        }

        return $outcome;
    }

    public function cancelPending(int $serviceId, string $status = 'cancelled'): void
    {
        $this->cancelled[$serviceId] = $status;
    }
}
