<?php

namespace App\Modules\Platform\Actions\Clients;

use App\Events\Core\ClientClosed;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Validation\ValidationException;

/**
 * Close or reopen a client account. Closing emits ClientClosed; the owning modules cancel
 * unpaid invoices and services (without driver termination) and turn off domain auto-renew.
 */
class CloseClient
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @throws ValidationException
     */
    public function handle(Client $client): void
    {
        if ($client->status === 'closed') {
            throw ValidationException::withMessages(['client' => __('This client is already closed.')]);
        }

        $previous = $client->status;
        $client->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->activity->log('Client closed', $client, $client->id, ['previous_status' => $previous], 'client.closed');
        ClientClosed::dispatch($client->id);
    }

    /**
     * @throws ValidationException
     */
    public function reopen(Client $client): void
    {
        if ($client->status !== 'closed' || $client->merged_into_client_id !== null) {
            throw ValidationException::withMessages(['client' => __('Only closed clients that were not merged can be reopened.')]);
        }

        $client->forceFill(['status' => 'active', 'closed_at' => null])->save();
        $this->activity->log('Client reopened', $client, $client->id, event: 'client.reopened');
    }
}
