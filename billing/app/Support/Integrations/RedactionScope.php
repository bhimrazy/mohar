<?php

namespace App\Support\Integrations;

use Closure;
use Throwable;
use WeakMap;

/**
 * The sensitive values of the driver calls in progress, for the log backstop (ADR 0023 §5). A host runs a driver call
 * inside during(): while it runs, every log record is also masked with the call's set, and an exception that leaves
 * it keeps the set, so a later report(), the worker's log line and `failed_jobs.exception` are masked by value too.
 *
 * Only the sets of calls in progress are held, each dropped when its call returns or throws. An exception keeps its
 * sets until it is freed (a WeakMap), so one job never masks with another job's set.
 *
 * @api
 */
final class RedactionScope
{
    /** Exceptions behind the first one whose attached sets are read, as Redactor::maskThrowable() masks them. */
    private const MAX_PREVIOUS = 16;

    /** @var list<RedactionSet> */
    private static array $active = [];

    /** @var WeakMap<Throwable, list<RedactionSet>>|null */
    private static ?WeakMap $attached = null;

    /**
     * Runs $call with $set active. A Throwable that leaves it is re-thrown with $set attached; when calls nest, each
     * one's set is added, never replaced.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public static function during(RedactionSet $set, Closure $call): mixed
    {
        self::$active[] = $set;
        $depth = count(self::$active);

        try {
            return $call();
        } catch (Throwable $exception) {
            self::$attached ??= new WeakMap;
            $sets = self::$attached[$exception] ?? [];

            if (! in_array($set, $sets, true)) {
                self::$attached[$exception] = [...$sets, $set];
            }

            throw $exception;
        } finally {
            // Back to the depth before this call, even if an inner call left a set behind.
            self::$active = array_slice(self::$active, 0, $depth - 1);
        }
    }

    /**
     * Drops the sets of calls in progress. The app calls it where no call can be in progress (between a worker's jobs,
     * after a response is sent), so a set a call failed to remove (a Fiber never resumed, a fatal error caught
     * outside) cannot mask a later job's output or keep its values in memory. Attached sets stay with their exceptions.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$active = [];
    }

    /**
     * The sets of the calls in progress, merged with those attached to $exception and the exceptions behind it.
     */
    public static function current(?Throwable $exception = null): RedactionSet
    {
        $sets = self::$active;

        for ($hops = 0; $exception !== null && self::$attached !== null && $hops <= self::MAX_PREVIOUS; $hops++, $exception = $exception->getPrevious()) {
            array_push($sets, ...(self::$attached[$exception] ?? []));
        }

        return RedactionSet::merge(...$sets);
    }
}
