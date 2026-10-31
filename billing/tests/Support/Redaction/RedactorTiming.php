<?php

namespace Tests\Support\Redaction;

use App\Support\Integrations\Redactor;
use Closure;

/** Inputs and timings for the Redactor's linear-time and bounded-memory tests (RedactorShapesBudgetTest). */
final class RedactorTiming
{
    /** Repeat $unit (given its index) until the text is $bytes long. */
    public static function input(int $bytes, Closure $unit): string
    {
        $text = '';

        for ($index = 0; strlen($text) < $bytes; $index++) {
            $text .= $unit($index);
        }

        return $text;
    }

    /** CPU seconds (user + system) the process spends in $work: a descheduled process on a loaded machine does not count. */
    public static function cpuSeconds(Closure $work): float
    {
        $before = getrusage();
        $work();
        $after = getrusage();

        return ($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec'])
            + ($after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec']) / 1e6
            + ($after['ru_stime.tv_sec'] - $before['ru_stime.tv_sec'])
            + ($after['ru_stime.tv_usec'] - $before['ru_stime.tv_usec']) / 1e6;
    }

    /**
     * Complexity check: the least CPU time of $runs of $work at each of two input sizes, interleaved so a burst of other
     * work hits both. Returns [small, large]; compare the ratio, never an absolute time.
     *
     * @param  Closure(string): mixed  $work
     * @return array{0: float, 1: float}
     */
    public static function bestCpuPair(string $small, string $large, Closure $work, int $runs = 3): array
    {
        [$smallBest, $largeBest] = [INF, INF];

        for ($run = 0; $run < $runs; $run++) {
            $smallBest = min($smallBest, self::cpuSeconds(fn () => $work($small)));
            $largeBest = min($largeBest, self::cpuSeconds(fn () => $work($large)));
        }

        return [$smallBest, $largeBest];
    }

    /**
     * Best of $runs CPU timings of masking $text, each on a fresh instance so one run's key cache does not flatter the next.
     *
     * @param  list<string>  $secrets
     */
    public static function bestSeconds(string $text, int $runs = 3, array $secrets = []): float
    {
        $best = INF;

        for ($run = 0; $run < $runs; $run++) {
            $best = min($best, self::cpuSeconds(fn () => (new Redactor)->maskValues($text, $secrets)));
        }

        return $best;
    }
}
