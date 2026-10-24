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
 * through the Redactor (keys, values and objects as redact() reads them), and a Throwable in `context` has its message
 * chain masked in place and is kept for the formatter. It backs up the hosts' own masking and never replaces it.
 *
 * It never throws. If masking fails, or a context object logs while the processor runs, the record is withheld: the
 * message becomes `[redacted]`, `context` and `extra` are dropped and `extra.redaction_failed` is set.
 *
 * @internal
 */
final class RedactingProcessor implements ProcessorInterface
{
    private static bool $running = false;

    public function __construct(private readonly Redactor $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        if (self::$running) {
            return self::withheld($record);
        }

        self::$running = true;

        try {
            $throwables = array_filter($record->context, fn (mixed $value): bool => $value instanceof Throwable);
            $set = RedactionSet::merge(RedactionScope::current(), ...array_map(RedactionScope::current(...), array_values($throwables)));

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
            }
        }

        return $this->redactor->redact($values, $set) + $throwables;
    }

    private static function withheld(LogRecord $record): LogRecord
    {
        return $record->with(message: Redactor::MASK, context: [], extra: ['redaction_failed' => true]);
    }
}
