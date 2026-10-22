<?php

namespace Mohar\Simulators\Providers\Khalti;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\RedactsPayments;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * Khalti KPG-2 web checkout for the PaymentsNepal driver (ADR 0016 §2), written from docs.khalti.com:
 *
 * - `epayment/initiate` and `epayment/lookup` under /api/v2, with `Authorization: Key <secret>`;
 * - the hosted page the payment_url opens (Pay, Pay with a later bank confirmation, Cancel), which sends the browser
 *   back to return_url with an unsigned query, as Khalti does;
 * - merchant refunds under /api/merchant-transaction.
 *
 * Control actions give the outcomes a test or the E2E runner needs without a browser (see actions()). The key is for
 * local scenarios only.
 */
final class KhaltiSimulator implements HasControlActions, RedactsPayments, Simulator
{
    public const SECRET_KEY = 'test_secret_key_SimulatorLocalScenario0000';

    public function key(): string
    {
        return 'khalti';
    }

    public function reference(): array
    {
        return ['url' => 'https://docs.khalti.com/khalti-epayment/', 'version' => 'KPG-2 (epayment v2)'];
    }

    public function routes(): array
    {
        $authorised = fn (\Closure $handler): \Closure => function (Request $request, Scenario $scenario, array $parameters) use ($handler): Response {
            if ($request->header('Authorization') !== 'Key '.self::SECRET_KEY) {
                return Response::json(['detail' => 'The authorization key is not valid.', 'status_code' => 401], 401);
            }

            return $handler(new KhaltiApi($scenario), $request->input(), $parameters);
        };

        return [
            new Route('POST', '/api/v2/epayment/initiate/', 'initiate', $authorised(fn (KhaltiApi $api, array $input) => $api->initiate($input))),
            new Route('POST', '/api/v2/epayment/lookup/', 'lookup', $authorised(fn (KhaltiApi $api, array $input) => $api->lookup($input))),
            new Route('POST', '/api/merchant-transaction/{id}/refund/', 'refund', $authorised(fn (KhaltiApi $api, array $input, array $parameters) => $api->refund($parameters['id'], $input))),
            new Route('GET', '/', 'payment_page', fn (Request $request, Scenario $scenario): Response => (new KhaltiApi($scenario))->page($request)),
            new Route('POST', '/pay/{pidx}/{choice}', 'payment_choice', function (Request $request, Scenario $scenario, array $parameters): Response {
                $outcome = (new KhaltiApi($scenario))->choose($parameters['pidx'], $parameters['choice']);

                return $outcome === null
                    ? new Response(410, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><p>This payment link has expired or was already used.</p>')
                    : new Response(303, ['Location' => $outcome['redirect']]);
            }),
        ];
    }

    public function operations(): array
    {
        return array_map(fn (Route $route): string => $route->operation, $this->routes());
    }

    public function seed(Scenario $scenario): void
    {
        $scenario->state()->put('config', 'merchant', ['name' => 'Simulated Khalti merchant', 'secret_key' => 'set']);
    }

    public function appConfiguration(Scenario $scenario): array
    {
        $api = $scenario->apiUrl();

        return [
            'env' => [
                'INTEGRATION_ENDPOINTS' => (string) json_encode(['khalti.api' => $api.'/api/v2', 'khalti.refund' => $api.'/api/merchant-transaction', 'khalti.pay' => $api], JSON_UNESCAPED_SLASHES),
                'OUTBOUND_ALLOW_LOOPBACK' => 'true',
            ],
            'notes' => ['Khalti gateway: sandbox mode on, secret key '.self::SECRET_KEY.'.'],
        ];
    }

    /**
     * - `pay` {pidx, total_amount?, mobile?}: the payer completes (another total_amount is the amount-mismatch case);
     * - `pending` {pidx}: the payer paid but the bank has not confirmed; `cancel` {pidx}: the payer cancels.
     *   These three return `redirect`, the return URL the browser follows into the app;
     * - `complete` and `expire` {pidx}: what Khalti later does to an open or Pending session;
     * - `refund` {pidx, amount?}: a refund made in the merchant portal (all, or `amount` paisa).
     */
    public function actions(): array
    {
        $choice = fn (string $choice): \Closure => function (array $input, Scenario $scenario) use ($choice): array {
            return (new KhaltiApi($scenario))->choose((string) $input['pidx'], $choice, $input)
                ?? throw new \InvalidArgumentException("Session {$input['pidx']} is not open.");
        };

        return [
            'pay' => $choice('pay'),
            'pending' => $choice('pending'),
            'cancel' => $choice('cancel'),
            'complete' => fn (array $input, Scenario $scenario): array => ['status' => (new KhaltiApi($scenario))->settle((string) $input['pidx'], 'Completed')],
            'expire' => fn (array $input, Scenario $scenario): array => ['status' => (new KhaltiApi($scenario))->settle((string) $input['pidx'], 'Expired')],
            'refund' => function (array $input, Scenario $scenario): array {
                $api = new KhaltiApi($scenario);
                $payment = $api->payment((string) $input['pidx']);

                if ($payment === null || ! in_array($payment['status'], ['Completed', 'Partially Refunded'], true)) {
                    throw new \InvalidArgumentException("Session {$input['pidx']} has no refundable payment.");
                }

                $api->refunded($payment, (int) ($input['amount'] ?? (KhaltiApi::paid($payment) - (int) $payment['refunded_amount'])));

                return ['status' => $api->payment((string) $input['pidx'])['status'] ?? null];
            },
        ];
    }
}
