<?php

namespace Tests\Fakes\Orders;

use App\Contracts\Services\ClientServiceDirectory;

class FakeClientServices implements ClientServiceDirectory
{
    /** @var array<int, list<array{id: int, label: string, status: string}>> client id => services */
    public array $services = [];

    public function forClient(int $clientId): array
    {
        return $this->services[$clientId] ?? [];
    }

    public function belongsToClient(int $serviceId, int $clientId): bool
    {
        return in_array($serviceId, array_column($this->forClient($clientId), 'id'), true);
    }
}
