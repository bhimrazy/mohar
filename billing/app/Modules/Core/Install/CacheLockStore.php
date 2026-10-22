<?php

namespace App\Modules\Core\Install;

/**
 * Whether `Cache::lock()` is safe across processes. Per-service provisioning locks and per-attempt payment locks only
 * exclude each other when every web and queue process reads and writes one lock store. Resolved the way Laravel does for
 * locks: `cache.default`, its driver, and for a `failover` store each store it lists (the weakest one counts).
 *
 * - `database`, `redis`, `memcached`, `dynamodb`: shared by all hosts (a database store whose `lock_connection` is
 *   named must name a configured connection).
 * - `file`: shared only by processes on one host and one `storage/` directory.
 * - `array`, `null`: per process, so no lock excludes another process. Not safe anywhere.
 */
final class CacheLockStore
{
    private const SHARED = ['database', 'redis', 'memcached', 'dynamodb'];

    private const SINGLE_HOST = ['file'];

    /**
     * @return array{status: CheckStatus, detail: string}
     */
    public function assess(): array
    {
        $name = (string) config('cache.default');
        $problems = [];
        $worst = CheckStatus::Ok;

        foreach ($this->stores($name) as $store) {
            $driver = (string) config("cache.stores.{$store}.driver", $store);
            $label = "CACHE_STORE \"{$store}\" ({$driver})";
            $lockConnection = config("cache.stores.{$store}.lock_connection");

            if (in_array($driver, self::SHARED, true) && ($lockConnection === null || $lockConnection === '' || is_array(config("database.connections.{$lockConnection}")) || is_array(config("database.redis.{$lockConnection}")))) {
                continue;
            }

            if (in_array($driver, self::SHARED, true)) {
                $problems[] = "{$label} uses lock connection \"{$lockConnection}\", which is not configured. Fix the lock connection setting or remove it.";
                $worst = CheckStatus::Failed;
            } elseif (in_array($driver, self::SINGLE_HOST, true)) {
                $problems[] = "{$label} is shared only by processes on one host: locks do not exclude web or queue processes on another server. Safe on a single host only; use database or redis when you run more than one server or container.";
                $worst = $worst === CheckStatus::Failed ? $worst : CheckStatus::Warning;
            } else {
                $problems[] = "{$label} keeps locks inside one process, so provisioning and payment locks do not stop a second web or queue process. Set CACHE_STORE=database or redis.";
                $worst = CheckStatus::Failed;
            }
        }

        if ($problems === []) {
            return ['status' => CheckStatus::Ok, 'detail' => 'Locks use "'.$name.'", shared by all web and queue processes.'];
        }

        return ['status' => $worst, 'detail' => implode(' ', $problems)];
    }

    /**
     * @return list<string>
     */
    private function stores(string $name): array
    {
        if (config("cache.stores.{$name}.driver") !== 'failover') {
            return [$name];
        }

        $members = array_values(array_filter((array) config("cache.stores.{$name}.stores"), 'is_string'));

        return $members === [] ? [$name] : $members;
    }
}
