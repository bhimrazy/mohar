<?php

namespace App\Support;

/**
 * Queue names (ADR 0014). Jobs use these constants and no other queue names. Production runs three worker pools,
 * in this priority order: PROVIDER_POOL, MESSAGING_POOL and DEFAULT. Every job's $timeout plus LOCK_SLACK_SECONDS
 * stays below the connection's retry_after, and a provider pool's --timeout is POOL_TIMEOUT_SECONDS (ADR 0017).
 */
final class Queues
{
    public const DEFAULT = 'default';

    public const PROVISIONING = 'provisioning';

    public const REGISTRAR = 'registrar';

    public const PAYMENTS = 'payments';

    public const NOTIFICATIONS = 'notifications';

    public const WEBHOOKS = 'webhooks';

    /** Queues that call providers (panels, registrars, gateways): one pool with the long timeout. */
    public const PROVIDER_POOL = [self::PAYMENTS, self::PROVISIONING, self::REGISTRAR];

    /** Outgoing mail and webhooks: one pool. */
    public const MESSAGING_POOL = [self::NOTIFICATIONS, self::WEBHOOKS];

    /** Margin between a job's timeout and the end of its lock or reservation (provisioning lock slack). */
    public const LOCK_SLACK_SECONDS = 20;

    /** Worker --timeout for every pool: the provisioning job cap (180 s) plus the lock slack, below retry_after (240 s). */
    public const POOL_TIMEOUT_SECONDS = 200;

    /**
     * Every queue in priority order, for a single development worker.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::PROVIDER_POOL, ...self::MESSAGING_POOL, self::DEFAULT];
    }

    /**
     * The production worker commands, one per pool.
     *
     * @return list<string>
     */
    public static function workerCommands(): array
    {
        return array_map(
            fn (array $queues): string => 'php artisan queue:work --queue='.implode(',', $queues).' --tries=3 --timeout='.self::POOL_TIMEOUT_SECONDS.' --max-time=3600',
            [self::PROVIDER_POOL, self::MESSAGING_POOL, [self::DEFAULT]],
        );
    }
}
