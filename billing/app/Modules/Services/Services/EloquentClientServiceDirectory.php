<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\ClientServiceDirectory;
use App\Modules\Services\Models\Service;

/**
 * A client's (non-deleted) services for pickers in other modules.
 */
class EloquentClientServiceDirectory implements ClientServiceDirectory
{
    public function forClient(int $clientId): array
    {
        return array_values(Service::query()
            ->where('client_id', $clientId)
            ->orderBy('id')
            ->get(['id', 'domain', 'product_name', 'status'])
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'label' => trim(($service->product_name ?? 'Service').($service->domain ? " - {$service->domain}" : '')),
                'status' => $service->status->value,
            ])
            ->all());
    }

    public function belongsToClient(int $serviceId, int $clientId): bool
    {
        return Service::query()->whereKey($serviceId)->where('client_id', $clientId)->exists();
    }
}
