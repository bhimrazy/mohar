<?php

namespace Tests\Support\Mail;

use App\Support\Integrations\OutboundAddressGuard;

/** A throwaway SMTP server in a child process (smtp-stub.php) and a guard that resolves test host names offline. */
final class SmtpStub
{
    /** @var resource */
    private $process;

    public int $port;

    private function __construct(string $mode)
    {
        $pipes = [];
        $this->process = proc_open([PHP_BINARY, __DIR__.'/smtp-stub.php', $mode], [1 => ['pipe', 'w']], $pipes);
        $this->port = (int) fgets($pipes[1]);
    }

    /** @param  'echo'|'slow'|'tarpit'  $mode */
    public static function start(string $mode): self
    {
        return new self($mode);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    /** Host names the mail server tests use, resolved without DNS; anything else is a public address. */
    public static function guard(): OutboundAddressGuard
    {
        return new OutboundAddressGuard(fn (string $host): array => match ($host) {
            'localhost' => ['127.0.0.1'],
            'lan-relay.example.np' => ['192.168.1.10'],
            'metadata.example.np' => ['169.254.169.254'],
            default => ['93.184.216.34'],
        });
    }
}
