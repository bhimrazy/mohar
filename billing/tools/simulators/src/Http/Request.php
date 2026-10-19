<?php

namespace Mohar\Simulators\Http;

/**
 * One request the simulator received, as the provider's API would see it.
 */
final readonly class Request
{
    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers  lower-case names
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $headers = [],
        public string $body = '',
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (str_starts_with((string) $name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $name => $header) {
            if (isset($_SERVER[$name])) {
                $headers[$header] = (string) $_SERVER[$name];
            }
        }

        parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY), $parsed);
        $query = [];

        foreach ($parsed as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $query[$key] = (string) $value;
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
            $query,
            $headers,
            (string) file_get_contents('php://input'),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The body as JSON or form fields (whichever it is), merged over the query string.
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        $decoded = json_decode($this->body, true);

        if (! is_array($decoded)) {
            parse_str($this->body, $decoded);
        }

        return [...$this->query, ...$decoded];
    }
}
