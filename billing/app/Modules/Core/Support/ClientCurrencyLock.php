<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Client;
use App\Support\Clients\ClientCurrencyUsage;
use Illuminate\Support\Arr;

/**
 * Whether a client's currency can still change (#218). Amounts are stored in the client's currency, so once any module
 * holds amounts for the client (ClientCurrencyUsage) the currency is fixed. The reason names what holds them.
 */
class ClientCurrencyLock
{
    /** What each module's records are called in the reason; an unknown key reads as its words. */
    private const RECORDS = [
        'billing' => 'billing records',
        'services' => 'services',
        'domains' => 'domains',
        'orders' => 'orders',
    ];

    public function __construct(private ClientCurrencyUsage $usage) {}

    /**
     * Why the currency of this client can't change, or null when it can.
     */
    public function reason(Client $client): ?string
    {
        $keys = $this->usage->sourcesFor($client->id);

        if ($keys === []) {
            return null;
        }

        $records = array_map(fn (string $key): string => __(self::RECORDS[$key] ?? str_replace('_', ' ', $key)), $keys);

        return __('This client has :records in :currency, so the currency can\'t be changed.', [
            'records' => Arr::join($records, ', ', ' '.__('and').' '),
            'currency' => $client->currency,
        ]);
    }
}
