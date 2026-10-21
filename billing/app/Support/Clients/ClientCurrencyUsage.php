<?php

namespace App\Support\Clients;

use Closure;
use InvalidArgumentException;

/**
 * Which clients already have amounts stored in their currency, so the currency cannot be relabelled (client-import
 * spec §6.4). Each module that stores amounts per client registers a source in bootModule():
 *
 *   app(ClientCurrencyUsage::class)->source('services', fn (array $clientIds): array => Service::withTrashed()
 *       ->whereIn('client_id', $clientIds)->distinct()->pluck('client_id')->all());
 *
 * A source receives a batch of client ids and returns those it holds records for. With no source registered (the
 * modules are absent) no client is in use. Sources are asked in registration order, each only for the clients not
 * found yet.
 */
class ClientCurrencyUsage
{
    /** @var array<string, Closure(list<int>): list<int|string>> */
    private array $sources = [];

    /**
     * @param  Closure(list<int>): list<int|string>  $clientsWithRecords
     */
    public function source(string $key, Closure $clientsWithRecords): void
    {
        if (isset($this->sources[$key])) {
            throw new InvalidArgumentException("A client currency usage source [{$key}] is already registered.");
        }

        $this->sources[$key] = $clientsWithRecords;
    }

    /**
     * The clients among $clientIds that some module holds amounts for.
     *
     * @param  list<int>  $clientIds
     * @return list<int>
     */
    public function clientsInUse(array $clientIds): array
    {
        $found = [];

        foreach ($this->sources as $source) {
            $pending = array_values(array_diff($clientIds, $found));

            if ($pending === []) {
                break;
            }

            array_push($found, ...array_map(intval(...), $source($pending)));
        }

        return array_values(array_unique(array_intersect($found, $clientIds)));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->sources);
    }
}
