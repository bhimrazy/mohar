<?php

namespace App\Support;

/**
 * Queue names and the worker pools that consume them (ADR 0014), in one place for the jobs, config/queue.php, the
 * `app:install` hint and docs/devops/install.md (tested against each other).
 *
 * The pools share their connection's retry_after (240 s by default). Work that can run longer than that has its own
 * connection, AUTOMATION, whose retry_after is above the job's timeout: a queue worker hands a reserved job to a
 * second worker once retry_after has passed, even while the first is still running it (ADR 0017 §3, issue #82).
 */
final class Queues
{
    public const DEFAULT = 'default';

    public const PROVISIONING = 'provisioning';

    public const REGISTRAR = 'registrar';

    public const PAYMENTS = 'payments';

    public const NOTIFICATIONS = 'notifications';

    public const WEBHOOKS = 'webhooks';

    /**
     * Client imports (staging, checking and applying uploaded files), worked by the default pool after `default`: a
     * 50,000-row import delays `default` work by at most one import job. Each import job stops after
     * IMPORT_JOB_TIMEOUT_SECONDS, below the pool's --timeout and the connection's 240 s retry_after, so the queue
     * needs no connection of its own; longer work is split into chunks.
     */
    public const IMPORTS = 'imports';

    /** The longest an import job runs ($timeout on every import job). */
    public const IMPORT_JOB_TIMEOUT_SECONDS = 120;

    /** Worker --timeout of the default pool: the import job cap plus slack to stop it. */
    public const DEFAULT_POOL_TIMEOUT_SECONDS = 150;

    /** Worker --memory of the default pool (MB): an import job may peak at 192 MB (client-import spec §13). */
    public const DEFAULT_POOL_MEMORY_MB = 256;

    /** Worker --timeout of the provider pool: the provisioning job cap (180 s) plus the 20 s lock slack. */
    public const PROVIDER_POOL_TIMEOUT_SECONDS = 200;

    /** Connection and queue name for the automation "Run now" job. */
    public const AUTOMATION = 'automation';

    /** Longest automation run a worker allows before it stops the job (a billing day at 50k clients took 546 s). */
    public const AUTOMATION_TIMEOUT_SECONDS = 3600;

    /** retry_after of the automation connection: the timeout plus a margin for the worker to stop the job. */
    public const AUTOMATION_RETRY_AFTER_SECONDS = self::AUTOMATION_TIMEOUT_SECONDS + 300;

    /**
     * Default drivers the automation connection keeps. sync is kept for tests (the install pre-flight warns about it in
     * production). Any other falls back to database: deferred runs the job in the web process after the response,
     * background in a child process outside any worker, and failover pushes to connections with their own retry_after.
     */
    private const QUEUED_DRIVERS = ['database', 'redis', 'beanstalkd', 'sqs', 'sync'];

    /**
     * The automation connection: the default connection's backend with its own queue and retry_after.
     *
     * @param  array<string, array<string, mixed>>  $connections  the other entries of queue.connections
     * @return array<string, mixed>
     */
    public static function automationConnection(array $connections, string $default): array
    {
        $base = $connections[$default] ?? [];

        if (! in_array($base['driver'] ?? null, self::QUEUED_DRIVERS, true)) {
            $base = $connections['database'];
        }

        // On SQS the queue's visibility timeout plays the part of retry_after: set it on the queue itself.
        if ($base['driver'] === 'sqs') {
            return [...$base, 'queue' => self::AUTOMATION];
        }

        return [...$base, 'queue' => self::AUTOMATION, 'retry_after' => self::AUTOMATION_RETRY_AFTER_SECONDS];
    }

    /**
     * The production worker commands, one per pool, in the order install.md lists them.
     *
     * @return list<string>
     */
    public static function workerCommands(): array
    {
        return [
            'php artisan queue:work --queue='.implode(',', [self::PAYMENTS, self::PROVISIONING, self::REGISTRAR]).' --tries=3 --timeout='.self::PROVIDER_POOL_TIMEOUT_SECONDS,
            'php artisan queue:work --queue='.implode(',', [self::NOTIFICATIONS, self::WEBHOOKS]).' --tries=3',
            'php artisan queue:work --queue='.self::DEFAULT.','.self::IMPORTS.' --tries=3 --timeout='.self::DEFAULT_POOL_TIMEOUT_SECONDS.' --memory='.self::DEFAULT_POOL_MEMORY_MB,
            'php artisan queue:work '.self::AUTOMATION.' --queue='.self::AUTOMATION.' --tries=1 --timeout='.self::AUTOMATION_TIMEOUT_SECONDS,
        ];
    }
}
