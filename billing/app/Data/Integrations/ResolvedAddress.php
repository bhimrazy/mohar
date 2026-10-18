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
     * The CURLOPT_CONNECT_TO entry that sends every connection of the request to $ip:$port, whatever host and port
     * the URL names (both fields empty, so they match anything). The URL's host is still used for TLS and the Host
     * header.
     */
    public function curlConnectTo(): string
    {
        $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

        return "::{$ip}:{$this->port}";
    }
}
