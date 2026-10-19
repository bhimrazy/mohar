<?php

namespace Mohar\Simulators\Providers\Stripe;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\SignsCallbacks;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * Stripe in test mode for the PaymentsStripe driver (ADR 0016 §2):
 *
 * - Checkout Sessions (create, retrieve, expire), with a hosted page on the same TLS port: Pay, Decline and Back
 *   (abandon), which redirects to the session's return URLs;
 * - the PaymentIntent, Charge and balance transaction a payment creates; Refunds; Disputes raised from the control
 *   plane; the account;
 * - every change as an event, signed `t=…,v1=…` with HMAC-SHA256 and queued for the app's webhook endpoint.
 *
 * The keys below are for local scenarios only.
 */
final class StripeSimulator implements HasControlActions, SignsCallbacks, Simulator
{
    public const SECRET_KEY = 'sk_test_51SimulatorLocalScenarioKey0000';

    public const WEBHOOK_SECRET = 'whsec_SimulatorLocalScenarioSecret00';

    public function key(): string
    {
        return 'stripe';
    }

    public function reference(): array
    {
        return ['url' => 'https://docs.stripe.com/api', 'version' => StripeApi::VERSIONS[0]];
    }

    public function routes(): array
    {
        $api = fn (callable $handler): \Closure => fn (Request $request, Scenario $scenario, array $parameters): Response => (new StripeApi($scenario))->handle($request, fn (array $input, array $parameters) => $handler(new StripeApi($scenario), $input, $parameters), $parameters);

        return [
            new Route('GET', '/v1/account', 'retrieve_account', $api(fn (StripeApi $stripe) => $stripe->account())),
            new Route('POST', '/v1/checkout/sessions', 'create_checkout_session', $api(fn (StripeApi $stripe, array $input) => $stripe->createSession($input))),
            new Route('GET', '/v1/checkout/sessions/{id}', 'retrieve_checkout_session', $api(fn (StripeApi $stripe, array $input, array $parameters) => $stripe->retrieve('checkout.session', $parameters['id'], $input))),
            new Route('POST', '/v1/checkout/sessions/{id}/expire', 'expire_checkout_session', $api(fn (StripeApi $stripe, array $input, array $parameters) => $stripe->expireSession($input, $parameters))),
            new Route('GET', '/v1/payment_intents/{id}', 'retrieve_payment_intent', $api(fn (StripeApi $stripe, array $input, array $parameters) => $stripe->retrieve('payment_intent', $parameters['id'], $input))),
            new Route('GET', '/v1/charges/{id}', 'retrieve_charge', $api(fn (StripeApi $stripe, array $input, array $parameters) => $stripe->retrieve('charge', $parameters['id'], $input))),
            new Route('POST', '/v1/refunds', 'create_refund', $api(fn (StripeApi $stripe, array $input) => $stripe->createRefund($input))),
            new Route('GET', '/v1/refunds/{id}', 'retrieve_refund', $api(fn (StripeApi $stripe, array $input, array $parameters) => $stripe->retrieve('refund', $parameters['id'], $input))),
            new Route('GET', '/v1/disputes/{id}', 'retrieve_dispute', $api(fn (StripeApi $stripe, array $input, array $parameters) => $stripe->retrieve('dispute', $parameters['id'], $input))),
            new Route('GET', '/c/pay/{id}', 'checkout_page', fn (Request $request, Scenario $scenario, array $parameters): Response => (new HostedCheckout($scenario))->page($parameters['id'])),
            new Route('POST', '/c/pay/{id}/{choice}', 'checkout_choice', fn (Request $request, Scenario $scenario, array $parameters): Response => (new HostedCheckout($scenario))->choose($parameters['id'], $parameters['choice'])),
        ];
    }

    public function operations(): array
    {
        return array_map(fn (Route $route): string => $route->operation, $this->routes());
    }

    public function seed(Scenario $scenario): void
    {
        $scenario->state()->put('config', 'account', ['id' => 'acct_1SimulatorAccount', 'object' => 'account', 'business_profile' => ['name' => 'Simulated Stripe account'], 'charges_enabled' => true, 'country' => 'US', 'default_currency' => 'usd']);
    }

    public function appConfiguration(Scenario $scenario): array
    {
        return [
            'env' => [
                'INTEGRATION_ENDPOINTS' => (string) json_encode(['stripe.api' => $scenario->apiUrl().'/v1', 'stripe.checkout' => $scenario->apiUrl()], JSON_UNESCAPED_SLASHES),
                'OUTBOUND_ALLOW_LOOPBACK' => 'true',
            ],
            'notes' => [
                'Stripe gateway: test mode on, secret key '.self::SECRET_KEY.', webhook signing secret '.self::WEBHOOK_SECRET.'.',
                'Tell the simulator where to send webhooks: POST /__sim/actions/webhook_url {"url": "<APP_URL>/webhooks/stripe"}, then deliver them from /__sim/outbox.',
            ],
        ];
    }

    public function sign(Scenario $scenario, string $body, int $timestamp, bool $wrongSecret = false): array
    {
        $secret = $wrongSecret ? 'whsec_NotTheSecretTheAppKnows000000000' : self::WEBHOOK_SECRET;

        return ['Stripe-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)];
    }

    public function actions(): array
    {
        return [
            'webhook_url' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'webhook_url', (string) $input['url']);

                return ['webhook_url' => $input['url']];
            },
            // The cardholder disputes a payment with their bank.
            'dispute' => function (array $input, Scenario $scenario): array {
                $stripe = new StripeApi($scenario);
                $intent = $stripe->find((string) $input['payment_intent']);
                $charge = is_array($intent) ? $stripe->find((string) $intent['latest_charge']) : null;

                if ($charge === null) {
                    throw new \InvalidArgumentException('No paid PaymentIntent '.$input['payment_intent'].'.');
                }

                $dispute = [
                    'id' => $stripe->newId('du_'),
                    'object' => 'dispute',
                    'amount' => (int) ($input['amount'] ?? $charge['amount']),
                    'charge' => $charge['id'],
                    'created' => time(),
                    'currency' => $charge['currency'],
                    'livemode' => false,
                    'metadata' => [],
                    'payment_intent' => $charge['payment_intent'],
                    'reason' => (string) ($input['reason'] ?? 'fraudulent'),
                    'status' => 'needs_response',
                ];
                $scenario->state()->put('disputes', $dispute['id'], $dispute);
                $scenario->state()->put('charges', $charge['id'], [...$charge, 'disputed' => true]);
                $stripe->emit('charge.dispute.created', $dispute);

                return ['dispute' => $dispute['id']];
            },
        ];
    }
}
