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
     * @param  (Closure(Request): bool)|null  $when  a further test on the request, for APIs that pick the operation by a
     *                                               parameter on one URL (Namecheap's `Command=`); routes are tried in order
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $operation,
        public Closure $handler,
        public ?Closure $when = null,
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

        if (preg_match($pattern, $request->path, $matches) !== 1 || ($this->when !== null && ! ($this->when)($request))) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
