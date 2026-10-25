<?php

namespace Mohar\Simulators\Providers\Razorpay;

use Closure;
use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\RedactsPayments;
use Mohar\Simulators\Contracts\SignsCallbacks;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * Razorpay's India API in test mode (ADR 0016 §2), written from razorpay.com/docs/api:
 *
 * - orders, the payments paid against them, capture, refunds (full and partial) and payment links under /v1, with
 *   HTTP Basic `key_id:key_secret`;
 * - a hosted checkout and a payment-link page on the same TLS port, returning the signed result the merchant verifies;
 * - every change as a webhook event, signed with HMAC-SHA256 of the raw body (`X-Razorpay-Signature`).
 *
 * India is simulated only: the Razorpay driver (app/Modules/PaymentsRazorpay) runs against it, with no live capture yet
 * (docs-sourced shapes). The keys are for local scenarios only.
 */
final class RazorpaySimulator implements HasControlActions, RedactsPayments, SignsCallbacks, Simulator
{
    /** Assumed from Razorpay's examples; not confirmed by their docs. Change here if a real key id differs. */
    public const KEY_PREFIX = 'rzp_test_';

    public const KEY_ID = self::KEY_PREFIX.'SimulatorLocal01';

    public const KEY_SECRET = 'SimulatorLocalScenarioSecret0000';

    public const WEBHOOK_SECRET = 'SimulatorLocalWebhookSecret0000';

    public function key(): string
    {
        return 'razorpay';
    }

    public function reference(): array
    {
        return ['url' => 'https://razorpay.com/docs/api/', 'version' => 'v1 (orders, payments, refunds, payment links)'];
    }

    public function routes(): array
    {
        $authorised = fn (Closure $handler): Closure => function (Request $request, Scenario $scenario, array $parameters) use ($handler): Response {
            if (! hash_equals('Basic '.base64_encode(self::KEY_ID.':'.self::KEY_SECRET), (string) $request->header('Authorization'))) {
                return RazorpayApi::error('The API key provided is invalid.', 401);
            }

            return $handler(new RazorpayApi($scenario), $request->input(), $parameters);
        };
        $page = fn (Closure $handler): Closure => fn (Request $request, Scenario $scenario, array $parameters): Response => $handler(new RazorpayPages($scenario), $request, $parameters);

        return [
            new Route('POST', '/v1/orders', 'create_order', $authorised(fn (RazorpayApi $api, array $input) => $api->createOrder($input))),
            new Route('GET', '/v1/orders/{id}', 'fetch_order', $authorised(fn (RazorpayApi $api, array $input, array $p) => $api->fetchOrder($p['id']))),
            new Route('GET', '/v1/orders/{id}/payments', 'fetch_order_payments', $authorised(fn (RazorpayApi $api, array $input, array $p) => $api->orderPayments($p['id']))),
            new Route('GET', '/v1/payments/{id}', 'fetch_payment', $authorised(fn (RazorpayApi $api, array $input, array $p) => $api->fetchPayment($p['id']))),
            new Route('POST', '/v1/payments/{id}/capture', 'capture_payment', $authorised(fn (RazorpayApi $api, array $input, array $p) => $api->capture($p['id'], $input))),
            new Route('POST', '/v1/payments/{id}/refund', 'create_refund', $authorised(fn (RazorpayApi $api, array $input, array $p) => $api->refund($p['id'], $input))),
            new Route('GET', '/v1/payments/{id}/refunds', 'fetch_payment_refunds', $authorised(fn (RazorpayApi $api, array $input, array $p) => $api->paymentRefunds($p['id']))),
            new Route('GET', '/v1/refunds/{id}', 'fetch_refund', $authorised(fn (RazorpayApi $api, array $input, array $p) => $api->fetchRefund($p['id']))),
            new Route('POST', '/v1/payment_links', 'create_payment_link', $authorised(fn (RazorpayApi $api, array $input) => $api->createLink($input))),
            new Route('GET', '/v1/payment_links/{id}', 'fetch_payment_link', $authorised(fn (RazorpayApi $api, array $input, array $p) => $api->fetchLink($p['id']))),
            new Route('POST', '/v1/payment_links/{id}/cancel', 'cancel_payment_link', $authorised(fn (RazorpayApi $api, array $input, array $p) => $api->cancelLink($p['id']))),
            new Route('GET', '/checkout', 'checkout_page', $page(fn (RazorpayPages $pages, Request $request) => $pages->checkout($request))),
            new Route('POST', '/checkout/{order}/{choice}', 'checkout_choice', $page(fn (RazorpayPages $pages, Request $request, array $p) => $pages->checkoutChoice($request, $p['order'], $p['choice']))),
            new Route('GET', '/l/{id}', 'link_page', $page(fn (RazorpayPages $pages, Request $request, array $p) => $pages->link($p['id']))),
            new Route('POST', '/l/{id}/{choice}', 'link_choice', $page(fn (RazorpayPages $pages, Request $request, array $p) => $pages->linkChoice($p['id'], $p['choice']))),
        ];
    }

    public function operations(): array
    {
        return array_map(fn (Route $route): string => $route->operation, $this->routes());
    }

    public function seed(Scenario $scenario): void
    {
        $scenario->state()->put('config', 'account', ['id' => RazorpayApi::ACCOUNT_ID, 'name' => 'Simulated Razorpay account']);
        $scenario->state()->put('config', 'capture', 'automatic');
    }

    public function appConfiguration(Scenario $scenario): array
    {
        return [
            'env' => [
                'INTEGRATION_ENDPOINTS' => (string) json_encode(['razorpay.api' => $scenario->apiUrl().'/v1', 'razorpay.checkout' => $scenario->apiUrl()], JSON_UNESCAPED_SLASHES),
                'OUTBOUND_ALLOW_LOOPBACK' => 'true',
            ],
            'notes' => [
                'Razorpay gateway: test mode on, key id '.self::KEY_ID.', key secret '.self::KEY_SECRET.', webhook secret '.self::WEBHOOK_SECRET.'.',
                'Tell the simulator where to send webhooks: POST /__sim/actions/webhook_url {"url": "<APP_URL>/billing/webhooks/razorpay"}, then deliver them from /__sim/outbox.',
                'The Razorpay driver reads the endpoint names above through Endpoints.',
            ],
        ];
    }

    /**
     * Razorpay signs the raw body only, with no timestamp, so $timestamp is unused and a `stale` variant is the same
     * as a normal delivery (a receiver dedupes on `X-Razorpay-Event-Id` instead).
     */
    public function sign(Scenario $scenario, string $body, int $timestamp, bool $wrongSecret = false): array
    {
        return ['X-Razorpay-Signature' => hash_hmac('sha256', $body, $wrongSecret ? 'NotTheSecretTheAppKnows0000000' : self::WEBHOOK_SECRET)];
    }

    /**
     * - `webhook_url` {url, enabled_events?}: where events go (all of them when no list);
     * - `capture_mode` {mode: automatic|manual}: manual leaves paid payments `authorized` until captured;
     * - `pay` {order_id | payment_link_id, outcome?: success|failed, method?, vpa?}: the payer pays or fails; returns
     *   `payment_id`, `status` and `callback` ({method POST, url, fields} for an order given a `callback_url`; the
     *   `redirect` URL for a payment link). A `vpa` of `failure@razorpay` fails, as Razorpay's test UPI id does;
     * - `capture` {payment_id}: the merchant's capture, from the control plane;
     * - `refund` {payment_id, amount?}: a refund made in the dashboard (all, or `amount` paise);
     * - `refund_failed` {refund_id}: the bank rejects a processed refund (`refund.failed`).
     */
    public function actions(): array
    {
        return [
            'webhook_url' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'webhook_url', (string) $input['url']);
                $events = is_array($input['enabled_events'] ?? null) ? array_values(array_map('strval', $input['enabled_events'])) : [];
                $scenario->state()->put('config', 'enabled_events', $events);

                return ['webhook_url' => $input['url'], 'enabled_events' => $events];
            },
            'capture_mode' => function (array $input, Scenario $scenario): array {
                if (! in_array($input['mode'] ?? null, ['automatic', 'manual'], true)) {
                    throw new \InvalidArgumentException('mode must be automatic or manual.');
                }

                $scenario->state()->put('config', 'capture', $input['mode']);

                return ['mode' => $input['mode']];
            },
            'pay' => function (array $input, Scenario $scenario): array {
                $api = new RazorpayApi($scenario);
                $link = isset($input['payment_link_id']) ? $api->link((string) $input['payment_link_id']) : null;
                $orderId = $link['order_id'] ?? (string) ($input['order_id'] ?? '');

                if (isset($input['payment_link_id']) && ($link === null || $link['status'] !== 'created')) {
                    throw new \InvalidArgumentException("Payment link {$input['payment_link_id']} is not open.");
                }

                $payment = $api->attempt($orderId, array_intersect_key($input, array_flip(['outcome', 'method', 'vpa', 'card', 'email', 'contact'])))
                    ?? throw new \InvalidArgumentException("Order {$orderId} is unknown or already paid.");
                $answer = ['payment_id' => $payment['id'], 'status' => $payment['status']];

                if ($link !== null) {
                    $paid = $payment['status'] !== 'failed';

                    return [...$answer, 'redirect' => $paid ? (new RazorpayPages($scenario))->linkRedirect((array) $api->link($link['id']), $payment) : null];
                }

                $callback = (string) ($input['callback_url'] ?? '');

                return [...$answer, 'callback' => $callback === '' ? null : ['method' => 'POST', 'url' => $callback, 'fields' => RazorpayApi::checkoutFields($payment)]];
            },
            'capture' => function (array $input, Scenario $scenario): array {
                $api = new RazorpayApi($scenario);
                $payment = $api->payment((string) $input['payment_id']);

                if ($payment === null) {
                    throw new \InvalidArgumentException("No payment {$input['payment_id']}.");
                }

                $answer = $api->capture($payment['id'], ['amount' => $payment['amount'], 'currency' => 'INR']);

                return ['status' => json_decode($answer->body, true)['status'] ?? throw new \InvalidArgumentException("Payment {$payment['id']} can't be captured.")];
            },
            'refund' => function (array $input, Scenario $scenario): array {
                $api = new RazorpayApi($scenario);
                $payment = $api->payment((string) $input['payment_id']);

                if ($payment === null || ! in_array($payment['status'], ['captured', 'refunded'], true) || $payment['amount_refunded'] >= $payment['amount']) {
                    throw new \InvalidArgumentException("Payment {$input['payment_id']} has nothing refundable.");
                }

                $left = $payment['amount'] - $payment['amount_refunded'];
                $amount = (int) ($input['amount'] ?? $left);

                if ($amount < 1 || $amount > $left) {
                    throw new \InvalidArgumentException("The refund must be 1 to {$left} paise.");
                }

                return ['refund_id' => $api->refunded($payment, $amount)['id']];
            },
            'refund_failed' => fn (array $input, Scenario $scenario): array => ['status' => (new RazorpayApi($scenario))->refundFailed((string) $input['refund_id'])['status']],
        ];
    }
}
