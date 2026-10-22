<?php

namespace Mohar\Simulators\Providers\Esewa;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\RedactsPayments;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * eSewa ePay v2 for the PaymentsNepal driver (ADR 0016 §2), written from developer.esewa.com.np and the UAT captures of
 * 2026-10-21 (tests/Fixtures/Esewa):
 *
 * - the form post, signed HMAC-SHA256 over its signed_field_names, which books a session (ES104 for a bad signature,
 *   a 400 for a reused transaction_uuid) and redirects to the payment page;
 * - the payment page (Pay, Go back, Cancel), whose success redirect carries base64 JSON signed the same way;
 * - the status check, the only thing that settles.
 *
 * eSewa has no refund API; refunds made in its portal are a control action. The secret is for local scenarios only.
 */
final class EsewaSimulator implements HasControlActions, RedactsPayments, Simulator
{
    public const PRODUCT_CODE = 'EPAYTEST';

    public const SECRET_KEY = 'SimulatorLocalEsewaSecret';

    /** Merchants this simulator knows: product code => signing secret. */
    public const MERCHANTS = [self::PRODUCT_CODE => self::SECRET_KEY];

    public function key(): string
    {
        return 'esewa';
    }

    public function reference(): array
    {
        return ['url' => 'https://developer.esewa.com.np/pages/Epay', 'version' => 'ePay v2'];
    }

    public function routes(): array
    {
        return [
            new Route('POST', '/api/epay/main/v2/form', 'form', fn (Request $request, Scenario $scenario): Response => (new EsewaApi($scenario))->book($request->input())),
            new Route('GET', '/api/epay/transaction/status/', 'transaction_status', fn (Request $request, Scenario $scenario): Response => (new EsewaApi($scenario))->status($request->query)),
            new Route('GET', '/epay/booking/{id}', 'payment_page', fn (Request $request, Scenario $scenario, array $parameters): Response => (new EsewaApi($scenario))->page($parameters['id'])),
            new Route('POST', '/epay/booking/{id}/{choice}', 'payment_choice', function (Request $request, Scenario $scenario, array $parameters): Response {
                $outcome = (new EsewaApi($scenario))->choose($parameters['id'], $parameters['choice']);

                return $outcome === null
                    ? new Response(410, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><p>This payment session has ended.</p>')
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
        $scenario->state()->put('config', 'merchant', ['product_code' => self::PRODUCT_CODE]);
    }

    public function appConfiguration(Scenario $scenario): array
    {
        $api = $scenario->apiUrl();

        return [
            'env' => [
                'INTEGRATION_ENDPOINTS' => (string) json_encode(['esewa.form' => $api.'/api/epay/main/v2/form', 'esewa.status' => $api.'/api/epay/transaction/status'], JSON_UNESCAPED_SLASHES),
                'OUTBOUND_ALLOW_LOOPBACK' => 'true',
            ],
            'notes' => ['eSewa gateway: sandbox mode on, product code '.self::PRODUCT_CODE.', secret key '.self::SECRET_KEY.'.'],
        ];
    }

    /**
     * - `pay`, `leave`, `cancel` {transaction_uuid, settled_amount?, variant?}: the payer's choice on the page
     *   (EsewaApi::choose()); they return `redirect`, the URL the browser follows into the app;
     * - `settle` {transaction_uuid, status}: what eSewa later does (COMPLETE, EXPIRED, AMBIGUOUS, FULL_REFUND,
     *   PARTIAL_REFUND).
     */
    public function actions(): array
    {
        $choice = fn (string $choice): \Closure => function (array $input, Scenario $scenario) use ($choice): array {
            $api = new EsewaApi($scenario);
            $booking = $api->booking(self::PRODUCT_CODE.':'.$input['transaction_uuid']);

            return ($booking === null ? null : $api->choose((string) $booking['id'], $choice, $input))
                ?? throw new \InvalidArgumentException("Booking {$input['transaction_uuid']} is not open.");
        };

        return [
            'pay' => $choice('pay'),
            'leave' => $choice('leave'),
            'cancel' => $choice('cancel'),
            'settle' => fn (array $input, Scenario $scenario): array => ['status' => (new EsewaApi($scenario))->settle(self::PRODUCT_CODE, (string) $input['transaction_uuid'], (string) $input['status'])],
        ];
    }
}
