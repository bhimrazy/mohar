<?php

namespace Mohar\Simulators\Http;

use Mohar\Simulators\Kernel\Scenario;

/**
 * Answers a request on the API port: finds the route, applies the fault planned for this call of the operation, runs
 * the handler and records both sides in the request log.
 */
final class ApiKernel
{
    public function __construct(private readonly Scenario $scenario) {}

    public function handle(Request $request): Response
    {
        foreach ($this->scenario->simulator()->routes() as $route) {
            $parameters = $route->match($request);

            if ($parameters !== null) {
                $response = $this->run($route, $request, $parameters);
                $this->scenario->log()->record($route->operation, $request, $response);

                return $response;
            }
        }

        $response = Response::json(['error' => 'No such route in the simulator.'], 404);
        $this->scenario->log()->record('unknown', $request, $response);

        return $response;
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function run(Route $route, Request $request, array $parameters): Response
    {
        $call = $this->scenario->state()->increment('calls', $route->operation);
        $fault = $this->scenario->faults()->for($route->operation, $call);
        $params = $fault->params ?? [];

        switch ($fault?->type) {
            case 'latency':
                usleep(1000 * ((int) $params['ms'] + random_int(0, max(0, (int) ($params['jitter'] ?? 0)))));
                break;
            case 'status':
                return new Response((int) $params['status'], ['Content-Type' => 'application/json'], (string) ($params['body'] ?? ''));
            case 'rate_limit':
                return Response::json(['error' => 'Too many requests.'], 429, ['Retry-After' => (string) $params['retry_after']]);
            case 'reset_before_commit':
                return new Response(500, ['X-Sim-Reset' => '1']);
            case 'rotate_certificate':
                $this->scenario->ca()->rotateLeaf();
                break;
        }

        $response = ($route->handler)($request, $this->scenario, $parameters);

        return match ($fault?->type) {
            'commit_then_hang' => $this->hang((int) $params['seconds'], $response),
            'truncate' => $response->withHeaders(['X-Sim-Truncate' => (string) $params['bytes']]),
            'slow_body' => $response->withHeaders(['X-Sim-Slow' => (string) $params['ms']]),
            default => $response,
        };
    }

    private function hang(int $seconds, Response $response): Response
    {
        sleep($seconds);

        return $response;
    }
}
