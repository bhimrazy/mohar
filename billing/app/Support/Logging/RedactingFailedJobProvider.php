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
 * else is passed to the wrapped provider.
 *
 * @internal
 */
final class RedactingFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    public function __construct(private readonly FailedJobProviderInterface $failer, private readonly Redactor $redactor) {}

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

    public function count($connection = null, $queue = null)
    {
        return $this->failer instanceof CountableFailedJobProvider ? $this->failer->count($connection, $queue) : count($this->failer->ids($queue));
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->failer instanceof PrunableFailedJobProvider ? $this->failer->prune($before) : 0;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->failer->{$method}(...$arguments);
    }
}
