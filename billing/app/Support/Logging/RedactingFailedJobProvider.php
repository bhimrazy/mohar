<?php

namespace App\Support\Logging;

use App\Support\Integrations\Redactor;
use DateTimeInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;

/**
 * Wraps the `queue.failer` binding (ADR 0023 §1.3): a failed job's exception reaches `failed_jobs.exception` only
 * after Redactor::maskThrowable(), with the shape rules and the RedactionScope sets attached to its chain. Everything
 * else is passed to the wrapped provider. wrap() returns a decorator that is countable or prunable only when the wrapped
 * provider is, so `queue:prune-failed` and the failed-job count still see what the provider supports.
 *
 * @internal
 */
class RedactingFailedJobProvider implements FailedJobProviderInterface
{
    final public function __construct(private readonly FailedJobProviderInterface $failer, private readonly Redactor $redactor) {}

    public static function wrap(FailedJobProviderInterface $failer, Redactor $redactor): self
    {
        $countable = $failer instanceof CountableFailedJobProvider;
        $prunable = $failer instanceof PrunableFailedJobProvider;

        return match (true) {
            $countable && $prunable => new class($failer, $redactor) extends RedactingFailedJobProvider implements CountableFailedJobProvider, PrunableFailedJobProvider
            {
                public function count($connection = null, $queue = null)
                {
                    return $this->countJobs($connection, $queue);
                }

                public function prune(DateTimeInterface $before)
                {
                    return $this->pruneJobs($before);
                }
            },
            $countable => new class($failer, $redactor) extends RedactingFailedJobProvider implements CountableFailedJobProvider
            {
                public function count($connection = null, $queue = null)
                {
                    return $this->countJobs($connection, $queue);
                }
            },
            $prunable => new class($failer, $redactor) extends RedactingFailedJobProvider implements PrunableFailedJobProvider
            {
                public function prune(DateTimeInterface $before)
                {
                    return $this->pruneJobs($before);
                }
            },
            default => new self($failer, $redactor),
        };
    }

    public function log($connection, $queue, $payload, $exception)
    {
        return $this->failer->log($connection, $queue, $payload, $this->redactor->maskThrowable($exception));
    }

    /** @return array<int, int|string> */
    public function ids($queue = null)
    {
        return $this->failer->ids($queue);
    }

    /** @return array<int, object> */
    public function all()
    {
        return $this->failer->all();
    }

    public function find($id)
    {
        return $this->failer->find($id);
    }

    public function forget($id)
    {
        return $this->failer->forget($id);
    }

    public function flush($hours = null)
    {
        $this->failer->flush($hours);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->failer->{$method}(...$arguments);
    }

    protected function countJobs(?string $connection, ?string $queue): int
    {
        return $this->failer instanceof CountableFailedJobProvider ? $this->failer->count($connection, $queue) : 0;
    }

    protected function pruneJobs(DateTimeInterface $before): int
    {
        return $this->failer instanceof PrunableFailedJobProvider ? $this->failer->prune($before) : 0;
    }
}
