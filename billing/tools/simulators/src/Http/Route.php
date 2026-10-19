<?php

namespace Mohar\Simulators\Http;

use Closure;
use Mohar\Simulators\Kernel\Scenario;

/**
 * One API route. `{name}` segments in the path are passed to the handler; $operation is what faults and the request
 * log name it by.
 */
final readonly class Route
{
    /**
     * @param  Closure(Request, Scenario, array<string, string>): Response  $handler
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $operation,
        public Closure $handler,
    ) {}

    /**
     * The path parameters when the request matches, or null.
     *
     * @return array<string, string>|null
     */
    public function match(Request $request): ?array
    {
        if ($this->method !== '*' && strtoupper($this->method) !== $request->method) {
            return null;
        }

        $pattern = '#^'.preg_replace('#\\\\\{([a-z_]+)\\\\\}#', '(?P<$1>[^/]+)', preg_quote($this->path, '#')).'$#';

        if (preg_match($pattern, $request->path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
