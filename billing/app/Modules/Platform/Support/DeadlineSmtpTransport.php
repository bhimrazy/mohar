<?php

namespace App\Modules\Platform\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * SMTP transport with an overall deadline. Symfony Mailer only bounds each socket read (`timeout`), so a server that
 * answers every step slowly could hold a request for many reads. This refuses to start a new command once the budget
 * since the connection began is spent; a read already waiting still ends by its own timeout, so a send ends within the
 * budget plus one read timeout.
 */
final class DeadlineSmtpTransport extends EsmtpTransport
{
    private ?float $deadline = null;

    public function __construct(string $host, int $port, ?bool $tls, private float $budget)
    {
        parent::__construct($host, $port, $tls);
    }

    public function start(): void
    {
        $this->deadline ??= microtime(true) + $this->budget;
        parent::start();
    }

    public function executeCommand(string $command, array $codes): string
    {
        if ($this->deadline !== null && microtime(true) > $this->deadline) {
            throw new TransportException(sprintf('The mail server did not finish within %d seconds.', (int) $this->budget));
        }

        return parent::executeCommand($command, $codes);
    }
}
