<?php

namespace App\Support\Logging;

use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\RedactionSet;
use App\Support\Integrations\Redactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * The log backstop of ADR 0023 §1.2: masks every record by shape and by the values of the RedactionScope sets it can
 * find (calls in progress, and those attached to a Throwable in the context). The message, `context` and `extra` go
 * through the Redactor (keys, values and objects as redact() reads them). A Throwable at the top of `context` or
 * `extra` has its message chain masked in place and is kept for the formatter; one nested in an array (up to
 * MAX_DEPTH) is masked the same way and logged as its class, message and file:line. It backs up the hosts' own
 * masking and never replaces it.
 *
 * It never throws. If masking fails, or a context object logs while the processor runs, the record is withheld: the
 * message becomes `[redacted]`, `context` and `extra` are dropped and `extra.redaction_failed` is set.
 *
 * @internal
 */
final class RedactingProcessor implements ProcessorInterface
{
    /** How deep in `context` and `extra` arrays a Throwable is looked for. */
    private const MAX_DEPTH = 8;

    private static bool $running = false;

    public function __construct(private readonly Redactor $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        if (self::$running) {
            return self::withheld($record);
        }

        self::$running = true;

        try {
            $throwables = [...self::throwables($record->context), ...self::throwables($record->extra)];
            $set = RedactionSet::merge(RedactionScope::current(), ...array_map(RedactionScope::current(...), $throwables));

            return $record->with(
                message: $this->redactor->maskValues($record->message, $set),
                context: $this->maskContext($record->context, $set),
                extra: $this->maskContext($record->extra, $set),
            );
        } catch (Throwable) {
            return self::withheld($record);
        } finally {
            self::$running = false;
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function maskContext(array $values, RedactionSet $set): array
    {
        $throwables = [];

        foreach ($values as $key => $value) {
            if ($value instanceof Throwable) {
                $throwables[$key] = $this->redactor->maskThrowable($value, $set);
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = $this->summarise($value, $set, 1);
            }
        }

        return $this->redactor->redact($values, $set) + $throwables;
    }

    /**
     * $values with each Throwable in it masked and replaced by its class, message and file:line.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function summarise(array $values, RedactionSet $set, int $depth): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof Throwable) {
                $masked = $this->redactor->maskThrowable($value, $set);
                $values[$key] = ['exception' => $masked::class, 'message' => $masked->getMessage(), 'at' => $masked->getFile().':'.$masked->getLine()];
            } elseif (is_array($value) && $depth < self::MAX_DEPTH) {
                $values[$key] = $this->summarise($value, $set, $depth + 1);
            }
        }

        return $values;
    }

    /**
     * The Throwables in $values and its arrays, up to MAX_DEPTH, whose attached RedactionScope sets mask the record.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<Throwable>
     */
    private static function throwables(array $values, int $depth = 0): array
    {
        $found = [];

        foreach ($values as $value) {
            if ($value instanceof Throwable) {
                $found[] = $value;
            } elseif (is_array($value) && $depth < self::MAX_DEPTH) {
                array_push($found, ...self::throwables($value, $depth + 1));
            }
        }

        return $found;
    }

    private static function withheld(LogRecord $record): LogRecord
    {
        return $record->with(message: Redactor::MASK, context: [], extra: ['redaction_failed' => true]);
    }
}
