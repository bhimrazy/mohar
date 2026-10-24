<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use LogicException;
use Monolog\Handler\NullHandler;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * The tap every log channel in `config/logging.php` lists (`stack` and `null` excepted; a stack reuses its channels'
 * handlers), and that RedactingLogManager applies to every channel it creates: puts RedactingProcessor on each handler,
 * after the handler's own processors. Monolog runs the logger's processors (PsrLogMessageProcessor, Laravel's context)
 * before any handler's, so placeholders are filled in before the record is masked. Tapping a handler twice keeps one
 * processor, and a NullHandler (writes nothing) is left alone. Any other handler that cannot take processors fails the
 * tap, so the channel falls back to the emergency logger, which RedactingLogManager masks too.
 *
 * @internal
 */
final class RedactLogs
{
    public function __construct(private readonly RedactingProcessor $processor) {}

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            throw new LogicException('RedactLogs can only tap a Monolog logger, not '.get_debug_type($monolog).'.');
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof NullHandler) {
                continue;
            }

            if (! $handler instanceof ProcessableHandlerInterface) {
                throw new LogicException('The log handler '.$handler::class.' cannot take the RedactingProcessor.');
            }

            // A handler runs its newest processor first, so the ones it has go back on after this one, which runs last.
            $existing = [];

            try {
                for (; ;) {
                    $existing[] = $handler->popProcessor();
                }
            } catch (LogicException) {
                // The handler has no processors left.
            }

            $handler->pushProcessor($this->processor);

            foreach (array_reverse($existing) as $processor) {
                if (! $processor instanceof RedactingProcessor) {
                    $handler->pushProcessor($processor);
                }
            }
        }
    }
}
