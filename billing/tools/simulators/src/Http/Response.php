<?php

namespace Mohar\Simulators\Http;

/**
 * A simulator's answer. Headers prefixed `X-Sim-` are instructions to the TLS front (faults) and never reach the app.
 */
final readonly class Response
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'application/json', ...$headers], (string) json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, [...$this->headers, ...$headers], $this->body);
    }
}
