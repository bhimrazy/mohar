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

    /**
     * Best of $runs timings of masking $text, each on a fresh instance so one run's key cache does not flatter the next.
     *
     * @param  list<string>  $secrets
     */
    public static function bestSeconds(string $text, int $runs = 3, array $secrets = []): float
    {
        $best = INF;

        for ($run = 0; $run < $runs; $run++) {
            $started = hrtime(true);
            (new Redactor)->maskValues($text, $secrets);
            $best = min($best, (hrtime(true) - $started) / 1e9);
        }

        return $best;
    }
}
