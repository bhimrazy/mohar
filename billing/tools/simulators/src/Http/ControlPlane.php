<?php

namespace Mohar\Simulators\Http;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Scenario;
use Throwable;

/**
 * `/__sim/*` on the control port (plain HTTP, 127.0.0.1 only, never the API port, so a driver cannot reach it). Every
 * call needs the scenario's token in `X-Sim-Token`.
 */
final class ControlPlane
{
    public function __construct(private readonly Scenario $scenario) {}

    public function handle(Request $request): Response
    {
        if (! hash_equals($this->scenario->token(), (string) $request->header('x-sim-token'))) {
            return Response::json(['error' => 'Missing or wrong X-Sim-Token.'], 403);
        }

        $input = $request->input();

        try {
            return match (true) {
                $request->path === '/__sim/health' => Response::json(['ok' => true, 'scenario' => $this->scenario->id()]),
                $request->path === '/__sim/log' => Response::json($this->scenario->log()->entries($input['operation'] ?? null)),
                $request->path === '/__sim/faults' && $request->method === 'POST' => $this->addFault($input),
                $request->path === '/__sim/faults' && $request->method === 'DELETE' => $this->clearFaults(),
                $request->path === '/__sim/rotate-certificate' && $request->method === 'POST' => $this->rotate(),
                $request->path === '/__sim/outbox' => Response::json($this->scenario->outbox()->pending()),
                str_starts_with($request->path, '/__sim/actions/') && $request->method === 'POST' => $this->action(substr($request->path, 15), $input),
                default => Response::json(['error' => 'No such control endpoint.'], 404),
            };
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function addFault(array $input): Response
    {
        $this->scenario->faults()->add((string) $input['operation'], Fault::fromArray(['type' => (string) $input['type'], 'params' => (array) ($input['params'] ?? [])]), isset($input['nth']) ? (int) $input['nth'] : null);

        return Response::json(['planned' => true], 201);
    }

    private function clearFaults(): Response
    {
        $this->scenario->faults()->clear();

        return Response::json(['cleared' => true]);
    }

    private function rotate(): Response
    {
        $this->scenario->ca()->rotateLeaf();

        return Response::json(['rotated' => true]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function action(string $name, array $input): Response
    {
        $simulator = $this->scenario->simulator();
        $action = $simulator instanceof HasControlActions ? ($simulator->actions()[$name] ?? null) : null;

        return $action === null
            ? Response::json(['error' => "No action [{$name}]."], 404)
            : Response::json($action($input, $this->scenario));
    }
}
