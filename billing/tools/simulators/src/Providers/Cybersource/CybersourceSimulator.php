<?php

namespace Mohar\Simulators\Providers\Cybersource;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\RedactsPayments;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * Cybersource Secure Acceptance Hosted Checkout plus the REST API, for the NIC Asia card driver (ADR 0016 §2), written
 * from developer.cybersource.com:
 *
 * - the HMAC-signed form post to `/pay`, the hosted page (accept, decline, review, error, cancel) and the signed
 *   browser return, plus the signed server-to-server notice;
 * - REST transaction search and lookup, and follow-on refunds, authenticated with HTTP Signature (CybersourceRest).
 *
 * Only the REST lookup settles. The keys are for local scenarios only. Shapes are docs-sourced; there is no capture from
 * an evaluation account yet (owner decision: simulate now, evaluate later).
 */
final class CybersourceSimulator implements HasControlActions, RedactsPayments, Simulator
{
    public const PROFILE_ID = '5b0f6c2e-3a41-4d7e-9c58-0a2e7d1f4b93';

    public const ACCESS_KEY = 'simaccesskey00000000000000000001';

    public const SECRET_KEY = 'SimulatorLocalCybersourceSecretKey';

    public const MERCHANT_ID = 'mohar_np_sim';

    public const REST_KEY_ID = '5a1e6f0c-7d52-4b8e-9a31-2c4f8d6e1b07';

    /** Base64, as the Business Center shows a shared secret; the HMAC key is its decoded bytes. */
    public const REST_SECRET = 'U2ltdWxhdG9yQ3liZXJzb3VyY2VSZXN0U2VjcmV0MDE=';

    public function key(): string
    {
        return 'cybersource';
    }

    public function reference(): array
    {
        return ['url' => 'https://developer.cybersource.com/docs/cybs/en-us/sa/developer/all/sa-hosted/secure-acceptance/sa-payment-txns.html', 'version' => 'Secure Acceptance Hosted Checkout, REST v2'];
    }

    public function routes(): array
    {
        $rest = fn (\Closure $handler): \Closure => function (Request $request, Scenario $scenario, array $parameters) use ($handler): Response {
            $denied = CybersourceRest::authenticate($request);

            return $denied ?? $handler(new CybersourceRest($scenario), $request, $parameters);
        };

        return [
            new Route('POST', '/pay', 'pay', fn (Request $request, Scenario $scenario): Response => (new CybersourceApi($scenario))->book($request->input())),
            new Route('GET', '/pay/session/{id}', 'payment_page', fn (Request $request, Scenario $scenario, array $parameters): Response => (new CybersourceApi($scenario))->page($parameters['id'])),
            new Route('POST', '/pay/session/{id}/{choice}', 'payment_choice', function (Request $request, Scenario $scenario, array $parameters): Response {
                $outcome = (new CybersourceApi($scenario))->choose($parameters['id'], $parameters['choice']);

                return $outcome === null
                    ? new Response(410, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><p>This payment session has ended.</p>')
                    : CybersourceApi::returnPage($outcome);
            }),
            new Route('POST', '/tss/v2/searches', 'search', $rest(fn (CybersourceRest $api, Request $request): Response => $api->search($request->input()))),
            new Route('GET', '/tss/v2/transactions/{id}', 'transaction', $rest(fn (CybersourceRest $api, Request $request, array $parameters): Response => $api->transaction($parameters['id']))),
            new Route('POST', '/pts/v2/payments/{id}/refunds', 'refund', $rest(fn (CybersourceRest $api, Request $request, array $parameters): Response => $api->refund($parameters['id'], $request->input()))),
        ];
    }

    public function operations(): array
    {
        return array_map(fn (Route $route): string => $route->operation, $this->routes());
    }

    public function seed(Scenario $scenario): void
    {
        $scenario->state()->put('config', 'merchant', ['profile_id' => self::PROFILE_ID, 'currencies' => ['NPR', 'USD']]);
    }

    public function appConfiguration(Scenario $scenario): array
    {
        $api = $scenario->apiUrl();

        return [
            'env' => [
                'INTEGRATION_ENDPOINTS' => (string) json_encode(['cybersource.pay' => $api.'/pay', 'cybersource.rest' => $api], JSON_UNESCAPED_SLASHES),
                'OUTBOUND_ALLOW_LOOPBACK' => 'true',
            ],
            'notes' => [
                'Cybersource gateway: test mode on, merchant id '.self::MERCHANT_ID.', profile id '.self::PROFILE_ID.', access key '.self::ACCESS_KEY.', secret key '.self::SECRET_KEY.', REST key id '.self::REST_KEY_ID.', REST shared secret '.self::REST_SECRET.'.',
                'Real hosts: testsecureacceptance.cybersource.com/pay (test), secureacceptance.cybersource.com/pay (live), secureacceptance.in.cybersource.com/pay (India live).',
                'Set the customer response URL and notice URL: POST /__sim/actions/receipt_url {"url": "<APP_URL>/..."} and notice_url, then deliver notices from /__sim/outbox.',
            ],
        ];
    }

    /**
     * - `accept`, `decline`, `review`, `error`, `cancel` {transaction_uuid, settled_amount?, settled_currency?, variant?}:
     *   the payer's choice on the page (CybersourceApi::choose()). They return `post` {url, fields}, the signed form the
     *   browser posts to the customer response URL. `settled_*` makes the transaction REST reports differ from the one the
     *   return claims; `variant` is `wrong_secret`, `tampered`, `unsigned`, `extra_field` or `missing_field` for a forged return;
     * - `resolve_review` {transaction_uuid, outcome: accept|reject}: Decision Manager's later verdict (and its notice);
     * - `receipt_url`, `notice_url` {url}: the profile's customer response and merchant notification URLs, used when the
     *   form does not carry `override_custom_receipt_page` / `override_backoffice_post_url`.
     */
    public function actions(): array
    {
        $choice = fn (string $choice): \Closure => function (array $input, Scenario $scenario) use ($choice): array {
            $api = new CybersourceApi($scenario);
            $session = $api->session((string) ($input['transaction_uuid'] ?? ''));

            return ($session === null ? null : $api->choose((string) $session['id'], $choice, $input))
                ?? throw new \InvalidArgumentException("Session {$input['transaction_uuid']} is not open.");
        };

        return [
            'accept' => $choice('accept'),
            'decline' => $choice('decline'),
            'review' => $choice('review'),
            'error' => $choice('error'),
            'cancel' => $choice('cancel'),
            'resolve_review' => fn (array $input, Scenario $scenario): array => (new CybersourceApi($scenario))->resolveReview((string) $input['transaction_uuid'], (string) $input['outcome']),
            'receipt_url' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'receipt_url', (string) $input['url']);

                return ['receipt_url' => $input['url']];
            },
            'notice_url' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'notice_url', (string) $input['url']);

                return ['notice_url' => $input['url']];
            },
        ];
    }
}
