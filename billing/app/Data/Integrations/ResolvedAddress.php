<?php

namespace App\Data\Integrations;

/**
 * A staff-entered host that passed the outbound address guard, with the one IP the connection must use.
 */
final readonly class ResolvedAddress
{
    public function __construct(
        public string $host,
        public string $ip,
        public int $port,
    ) {}

    /**
     * The CURLOPT_RESOLVE entry that pins $host:$port to $ip, so DNS cannot change between check and connect.
     */
    public function curlResolve(): string
    {
        $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
