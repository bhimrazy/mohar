<?php

namespace App\Modules\Core\Install;

/**
 * Whether `Cache::lock()` is safe across processes. Per-service provisioning locks and per-attempt payment locks only
 * exclude each other when every web and queue process reads and writes one lock store. Resolved the way Laravel does for
 * locks: `cache.default`, its driver, and for a `failover` store each store it lists (the weakest one counts).
 *
 * - `database`, `redis`, `memcached`, `dynamodb`: shared by all hosts. A `lock_connection` must exist in the family the
 *   driver reads: `database.connections` for `database`, `database.redis` (or one of its clusters) for `redis`.
 * - `file`, `apc`, `octane`: shared only on one host (one `storage/` directory, one server or process group).
 * - `array`, `null`, `session` and anything unknown: per process, so no lock excludes another process.
 */
final class CacheLockStore
{
    private const SHARED = ['database', 'redis', 'memcached', 'dynamodb'];

    private const SINGLE_HOST = ['file', 'apc', 'octane'];

    private const RISK = 'Background jobs and web requests may run the same task twice (a second provisioning or a double payment attempt).';

    /**
     * @return array{status: CheckStatus, detail: string}
     */
    public function assess(): array
    {
        $name = (string) config('cache.default');
        $isFailover = config("cache.stores.{$name}.driver") === 'failover';
        $problems = [];
        $worst = CheckStatus::Ok;

        foreach ($this->stores($name) as $store) {
            $driver = (string) config("cache.stores.{$store}.driver", $store);
            $source = $isFailover ? "The failover list of CACHE_STORE \"{$name}\" includes \"{$store}\" ({$driver}), which" : "CACHE_STORE \"{$store}\" ({$driver})";
            $fix = $isFailover ? 'Remove it from the list or use database or redis.' : 'Use database or redis (CACHE_STORE).';

            if (in_array($driver, self::SHARED, true)) {
                $lock = config("cache.stores.{$store}.lock_connection");

                if ($lock === null || $lock === '' || $this->lockConnectionExists($driver, (string) $lock)) {
                    continue;
                }

                $problems[] = "The lock connection \"{$lock}\" of the {$store} cache is not a {$driver} connection that exists, so the first lock would fail. Correct or remove it.";
                $worst = CheckStatus::Failed;
            } elseif (in_array($driver, self::SINGLE_HOST, true)) {
                $problems[] = ($isFailover ? "{$source} is" : "{$source} is").' shared only inside one server. With a second server or container, '.lcfirst(self::RISK).' Fine for a single server; otherwise use database or redis.';
                $worst = $worst === CheckStatus::Failed ? $worst : CheckStatus::Warning;
            } else {
                $problems[] = ($isFailover ? "{$source} is" : "{$source} is").' not shared between processes. '.self::RISK.' '.$fix;
                $worst = CheckStatus::Failed;
            }
        }

        if ($problems === []) {
            return ['status' => CheckStatus::Ok, 'detail' => 'Task locks use "'.$name.'", shared by all web and queue processes.'];
        }

        return ['status' => $worst, 'detail' => implode(' ', $problems)];
    }

    private function lockConnectionExists(string $driver, string $lock): bool
    {
        return match ($driver) {
            'database' => is_array(config("database.connections.{$lock}")),
            'redis' => is_array(config("database.redis.{$lock}")) && ! in_array($lock, ['client', 'options', 'clusters'], true)
                || is_array(config("database.redis.clusters.{$lock}")),
            default => true,
        };
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
