<?php

namespace App\Data\Integrations;

/**
 * Where a driver sends a provider's requests, and the HTTP options the destination needs (Endpoints::for()). The two
 * come together so a driver can never use an overridden URL without its pinning. There is deliberately no "is this
 * overridden" flag: a driver must behave the same either way (ADR 0016 §5), so it has nothing to branch on.
 *
 * $baseUrl is the default as written, or the override shaped like the default (with a trailing "/" exactly when the
 * default has one). url() joins a path with exactly one "/", so a default and an override give the same path.
 */
final readonly class Endpoint
{
    /**
     * @param  array<string, mixed>  $options  Guzzle request options: [] for the provider's own host
     */
    public function __construct(
        public string $baseUrl,
        public array $options = [],
    ) {}

    public function url(string $path = ''): string
    {
        return $path === '' ? $this->baseUrl : rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
