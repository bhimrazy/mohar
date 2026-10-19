<?php

namespace Mohar\Simulators\Tests\Fixtures;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\SignsCallbacks;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * A tiny provider for the framework's own tests: items behind a bearer key, and signed webhooks.
 */
final class EchoSimulator implements HasControlActions, SignsCallbacks, Simulator
{
    public const KEY = 'sk_test_echo';

    public function key(): string
    {
        return 'echo';
    }

    public function reference(): array
    {
        return ['url' => 'https://example.test/echo-api', 'version' => '2026-10-18'];
    }

    public function routes(): array
    {
        return [
            new Route('POST', '/v1/items', 'create_item', function (Request $request, Scenario $scenario): Response {
                if ($request->header('authorization') !== 'Bearer '.self::KEY) {
                    return Response::json(['error' => 'Invalid key.'], 401);
                }

                $id = 'item_'.$scenario->state()->increment('ids', 'item');
                $item = ['id' => $id, 'name' => (string) ($request->input()['name'] ?? '')];
                $scenario->state()->put('items', $id, $item);

                return Response::json($item, 201);
            }),
            new Route('GET', '/v1/items/{id}', 'get_item', function (Request $request, Scenario $scenario, array $parameters): Response {
                $item = $scenario->state()->get('items', $parameters['id']);

                return $item === null ? Response::json(['error' => 'No such item.'], 404) : Response::json($item);
            }),
        ];
    }

    public function seed(Scenario $scenario): void
    {
        $scenario->state()->put('config', 'webhook_secret', 'whsec_echo');
    }

    public function appConfiguration(Scenario $scenario): array
    {
        return ['env' => ['INTEGRATION_ENDPOINTS' => (string) json_encode(['echo.api' => $scenario->apiUrl()])], 'notes' => ['Use the key '.self::KEY.'.']];
    }

    public function operations(): array
    {
        return ['create_item', 'get_item'];
    }

    public function sign(Scenario $scenario, string $body, int $timestamp, bool $wrongSecret = false): array
    {
        $secret = $wrongSecret ? 'whsec_wrong' : (string) $scenario->state()->get('config', 'webhook_secret');

        return ['Echo-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)];
    }

    public function actions(): array
    {
        return [
            'emit' => function (array $input, Scenario $scenario): array {
                return ['delivery' => $scenario->outbox()->enqueue((string) $input['url'], (string) json_encode(['event' => $input['event'] ?? 'item.created']), ['Content-Type' => 'application/json'])];
            },
        ];
    }
}
