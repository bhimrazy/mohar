<?php

namespace App\Support\Clients;

use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * Which clients already have amounts stored in their currency, so the currency cannot be relabelled (client-import
 * spec §6.4). Each module that stores amounts per client registers a source in bootModule():
 *
 *   app(ClientCurrencyUsage::class)->register('services', fn (array $clientIds): array => Service::withTrashed()
 *       ->whereIn('client_id', $clientIds)->distinct()->pluck('client_id')->all());
 *
 * A source receives a batch of client ids and returns those it holds records for (ids may repeat or come as strings).
 * With no source registered (the modules are absent) no client is in use. Sources are asked in registration order,
 * each only for the clients not found yet. The key is the registering module's key (ADR 0012 addendum).
 *
 * @api
 */
class ClientCurrencyUsage
{
    private const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, Closure(list<int>): array<int|string>> */
    private array $sources = [];

    /**
     * @param  Closure(list<int>): array<int|string>  $source  the clients among the given ids this module holds amounts for
     */
    public function register(string $key, Closure $source, bool $replace = false): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid client currency usage key [{$key}]: use 1 to 50 lowercase letters, digits or underscores.");
        }

        if (isset($this->sources[$key])) {
            if (! $replace) {
                throw new LogicException("A client currency usage source is already registered as [{$key}]; pass replace: true to override it.");
            }

            Log::info('Client currency usage source replaced.', ['key' => $key]);
        }

        $this->sources[$key] = $source;
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

            array_push($found, ...array_map(intval(...), array_values($source($pending))));
        }

        return array_values(array_unique(array_intersect($found, $clientIds)));
    }

    /**
     * The keys of the sources that hold amounts for one client, in registration order: every source is asked, so a
     * refusal can name everything that blocks the change.
     *
     * @return list<string>
     */
    public function sourcesFor(int $clientId): array
    {
        $keys = [];

        foreach ($this->sources as $key => $source) {
            if (in_array($clientId, array_map(intval(...), array_values($source([$clientId]))), true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Registered keys, in registration order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->sources);
    }
}
