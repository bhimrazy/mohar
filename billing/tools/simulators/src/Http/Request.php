<?php

namespace Mohar\Simulators\Http;

/**
 * One request the simulator received, as the provider's API would see it.
 */
final readonly class Request
{
    /**
     * @param  array<array-key, mixed>  $query  as fields() reads it: dotted names kept, brackets nested
     * @param  array<string, string>  $headers  lower-case names
     * @param  string  $remoteAddress  the peer the backend saw: the TLS front on 127.0.0.1, as every client is local
     * @param  string  $rawQuery  the query string as sent, for APIs that repeat a name (values())
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $headers = [],
        public string $body = '',
        public string $remoteAddress = '127.0.0.1',
        public string $rawQuery = '',
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

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
            self::fields((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY)),
            $headers,
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY),
        );
    }

    /**
     * Every value sent under $name, in the query string and then a form body, in order: for APIs that send a list by
     * repeating the name without brackets (`ns=a&ns=b`), which fields() reads as its last value only.
     *
     * @return list<string>
     */
    public function values(string $name): array
    {
        $values = [];
        $form = json_decode($this->body) === null ? $this->body : '';

        foreach ([$this->rawQuery, $form] as $encoded) {
            foreach (explode('&', $encoded) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

                if ($pair !== '' && urldecode($key) === $name) {
                    $values[] = urldecode($value);
                }
            }
        }

        return $values;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The body as JSON or form fields (whichever it is), merged over the query string.
     *
     * @return array<array-key, mixed>
     */
    public function input(): array
    {
        $decoded = json_decode($this->body, true);

        return [...$this->query, ...(is_array($decoded) ? $decoded : self::fields($this->body))];
    }

    /**
     * Query-string or form fields as sent: names keep their dots (`api.version`, which parse_str would turn into
     * `api_version`), brackets nest as in PHP (`card[number]`, `items[]`), and a repeated name keeps its last value.
     *
     * @return array<array-key, mixed>
     */
    public static function fields(string $encoded): array
    {
        $fields = [];

        foreach (explode('&', $encoded) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            $path = [$name];

            if (preg_match('/^([^\[]+)((?:\[[^\]]*\])+)$/', $name, $match) === 1) {
                preg_match_all('/\[([^\]]*)\]/', $match[2], $parts);
                $path = [$match[1], ...$parts[1]];
            }

            $fields = self::place($fields, $path, urldecode($value));
        }

        return $fields;
    }

    /**
     * @param  array<array-key, mixed>  $target
     * @param  list<string>  $path  an empty segment appends (`items[]`)
     * @return array<array-key, mixed>
     */
    private static function place(array $target, array $path, string $value): array
    {
        $key = (string) array_shift($path);
        $child = $path === [] ? $value : self::place($key !== '' && is_array($target[$key] ?? null) ? $target[$key] : [], $path, $value);

        if ($key === '') {
            $target[] = $child;
        } else {
            $target[$key] = $child;
        }

        return $target;
    }
}
