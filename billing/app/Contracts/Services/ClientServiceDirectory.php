<?php

namespace App\Contracts\Services;

/**
 * Read-only list of a client's services for pickers in other modules (e.g. "related service" on a ticket).
 */
interface ClientServiceDirectory
{
    /**
     * @return list<array{id: int, label: string, status: string}>
     */
    public function forClient(int $clientId): array;

    public function belongsToClient(int $serviceId, int $clientId): bool;
}
