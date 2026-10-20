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
                [$response, $hang] = $this->run($route, $request, $parameters);
                // Logged before any hang: the call has committed, whether or not the client waits for the answer.
                $this->scenario->log()->record($route->operation, $request, $response);
                sleep($hang);

                return $response;
            }
        }

        $response = Response::json(['error' => 'No such route in the simulator.'], 404);
        $this->scenario->log()->record('unknown', $request, $response);

        return $response;
    }

    /**
     * The answer, and how many seconds to hold it back after committing (commit_then_hang).
     *
     * @param  array<string, string>  $parameters
     * @return array{Response, int}
     */
    private function run(Route $route, Request $request, array $parameters): array
    {
        $call = $this->scenario->state()->increment('calls', $route->operation);
        $fault = $this->scenario->faults()->for($route->operation, $call);
        $params = $fault->params ?? [];

        switch ($fault?->type) {
            case 'latency':
                usleep(1000 * ((int) $params['ms'] + random_int(0, max(0, (int) ($params['jitter'] ?? 0)))));
                break;
            case 'status':
                return [new Response((int) $params['status'], ['Content-Type' => 'application/json'], (string) ($params['body'] ?? '')), 0];
            case 'rate_limit':
                return [Response::json(['error' => 'Too many requests.'], 429, ['Retry-After' => (string) $params['retry_after']]), 0];
            case 'reset_before_commit':
                return [new Response(500, ['X-Sim-Reset' => '1']), 0];
            case 'rotate_certificate':
                $this->scenario->ca()->rotateLeaf();
                break;
        }

        $response = ($route->handler)($request, $this->scenario, $parameters);

        return match ($fault?->type) {
            'commit_then_hang' => [$response, (int) $params['seconds']],
            'truncate' => [$response->withHeaders(['X-Sim-Truncate' => (string) $params['bytes']]), 0],
            'slow_body' => [$response->withHeaders(['X-Sim-Slow' => (string) $params['ms']]), 0],
            default => [$response, 0],
        };
    }
}
