<?php

namespace App\Modules\Core\Install;

use App\Support\Queues;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * Compares the longest time a job may run with the `retry_after` of the connection that carries it (ADR 0014). A queue
 * hands a reserved job to a second worker once retry_after has passed, even while the first still runs it (issue #82),
 * so retry_after must be above the longest timeout.
 *
 * Two groups: the default connection carries every job except the automation run, and the `automation` connection
 * carries the automation run. The longest timeout of the first group is the greatest of the documented pool worker
 * timeout, the Services cap plus its lock slack, and every `$timeout` a job class declares with a value; the automation
 * run is the job whose declared timeout is Queues::AUTOMATION_TIMEOUT_SECONDS.
 */
final class QueueTimeouts
{
    /**
     * @param  list<class-string>|null  $classes  job classes to read instead of scanning app/ (tests)
     */
    public function __construct(private ?array $classes = null) {}

    /** Without --timeout a worker stops a job after this many seconds (the framework default). */
    private const WORKER_DEFAULT_TIMEOUT_SECONDS = 60;

    /** Same slack the Services lock adds to a job's timeout (ServiceSettings::LOCK_SLACK_SECONDS). */
    private const LOCK_SLACK_SECONDS = 20;

    /**
     * @return array{default: array{seconds: int, source: string}, automation: array{seconds: int, source: string}}
     */
    public function longest(): array
    {
        $default = ['seconds' => Queues::PROVIDER_POOL_TIMEOUT_SECONDS, 'source' => 'the provider worker pool (--timeout '.Queues::PROVIDER_POOL_TIMEOUT_SECONDS.')'];
        $consider = function (int $seconds, string $source) use (&$default): void {
            if ($seconds > $default['seconds']) {
                $default = ['seconds' => $seconds, 'source' => $source];
            }
        };

        $consider(self::WORKER_DEFAULT_TIMEOUT_SECONDS, 'the default worker timeout');
        $consider((int) config('services.module.max_job_timeout_seconds', 180) + self::LOCK_SLACK_SECONDS, 'services.module.max_job_timeout_seconds plus the lock slack');

        $automation = ['seconds' => Queues::AUTOMATION_TIMEOUT_SECONDS, 'source' => 'the automation run job'];

        foreach ($this->declaredJobTimeouts() as $class => $seconds) {
            if ($seconds === Queues::AUTOMATION_TIMEOUT_SECONDS) {
                continue;
            }

            $consider($seconds, class_basename($class).'::$timeout');
        }

        return ['default' => $default, 'automation' => $automation];
    }

    /**
     * The problems found, one message each; empty when every connection's retry_after is above its longest timeout.
     * SQS has no retry_after (its visibility timeout is set on the queue), so it is reported separately by the caller.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $longest = $this->longest();
        $problems = [];

        foreach ([
            'default' => (string) config('queue.default'),
            'automation' => Queues::AUTOMATION,
        ] as $group => $connection) {
            $driver = (string) config("queue.connections.{$connection}.driver", '');

            if (in_array($driver, ['sync', 'null', 'sqs', ''], true)) {
                continue;
            }

            $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 60);
            ['seconds' => $seconds, 'source' => $source] = $longest[$group];

            if ($retryAfter <= $seconds) {
                $problems[] = "Connection [{$connection}] has retry_after {$retryAfter} s, but a job may run for {$seconds} s ({$source}). "
                    .'The job would be handed to a second worker while it is still running. Raise retry_after above '.$seconds
                    .($group === 'default' ? ' (DB_QUEUE_RETRY_AFTER, REDIS_QUEUE_RETRY_AFTER or BEANSTALKD_QUEUE_RETRY_AFTER; 240 is the default)' : '').'.';
            }
        }

        return $problems;
    }

    /**
     * Every class in a `Jobs` directory under app/ (app/Jobs and any depth below a module) that declares a timeout.
     * Jobs that set it in the constructor are covered by the Services cap.
     *
     * @return array<class-string, int> job class => the timeout its declaration sets
     */
    public function declaredJobTimeouts(): array
    {
        $found = [];
        $classes = $this->classes ?? $this->discover();

        foreach ($classes as $class) {
            if (! class_exists($class)) {
                continue;
            }

            try {
                $defaults = (new ReflectionClass($class))->getDefaultProperties();
            } catch (Throwable) {
                continue;
            }

            if (isset($defaults['timeout']) && is_int($defaults['timeout']) && $defaults['timeout'] > 0) {
                $found[$class] = $defaults['timeout'];
            }
        }

        return $found;
    }

    /**
     * @return list<class-string>
     */
    private function discover(): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if ($file->getExtension() === 'php' && str_contains($path, '/Jobs/')) {
                /** @var class-string $class */
                $class = 'App\\'.str_replace('/', '\\', substr($path, strlen(str_replace(DIRECTORY_SEPARATOR, '/', app_path())) + 1, -4));
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
