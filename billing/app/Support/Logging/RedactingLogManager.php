<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;

/**
 * The `log` binding (ADR 0023 §1.2): every logger it hands out masks its records, not only the channels that list the
 * RedactLogs tap. A channel an operator adds to `config/logging.php` later, `Log::build()` and `Log::stack()` are
 * tapped as they are created, and so is the emergency logger that a channel failing to build falls back to (an
 * undefined LOG_CHANNEL, a broken custom driver, a handler RedactLogs refuses). Each logger's MessageLogged event
 * carries the masked message and context (RedactingLogEvents). Not final: `Log::shouldReceive()` mocks it in tests.
 *
 * @internal
 */
class RedactingLogManager extends LogManager
{
    /**
     * @param  string  $name
     */
    protected function tap($name, Logger $logger)
    {
        return $this->redacting(parent::tap($name, $logger));
    }

    protected function createEmergencyLogger()
    {
        $logger = parent::createEmergencyLogger();

        return $logger instanceof Logger ? $this->redacting($logger) : $logger;
    }

    /**
     * @param  array<int, string>  $channels
     * @param  string|null  $channel
     */
    public function stack(array $channels, $channel = null)
    {
        $logger = parent::stack($channels, $channel);

        return $logger instanceof Logger ? $this->redacting($logger) : $logger;
    }

    private function redacting(Logger $logger): Logger
    {
        $this->app->make(RedactLogs::class)($logger);
        $events = $logger->getEventDispatcher();

        if ($events !== null && ! $events instanceof RedactingLogEvents) {
            $logger->setEventDispatcher(new RedactingLogEvents($events, $this->app->make(RedactingProcessor::class)));
        }

        return $logger;
    }
}
