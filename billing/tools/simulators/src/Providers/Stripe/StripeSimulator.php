<?php

namespace Mohar\Simulators\Providers\Stripe;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\RecordsLive;
use Mohar\Simulators\Contracts\RedactsPayments;
use Mohar\Simulators\Contracts\SignsCallbacks;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Recorder;
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
 * Its request log and any recording apply the kernel payment redaction rule (RedactsPayments). The keys below are for
 * local scenarios only.
 */
final class StripeSimulator implements HasControlActions, RecordsLive, RedactsPayments, SignsCallbacks, Simulator
{
    public const SECRET_KEY = 'sk_test_51SimulatorLocalScenarioKey0000';

    public const WEBHOOK_SECRET = 'whsec_SimulatorLocalScenarioSecret00';

    /** The fee for receiving a dispute (Stripe US: 15.00 USD), in the charge currency's minor units. */
    public const DISPUTE_FEE = 1_500;

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
        // One StripeApi per request, so a validation failure the handler reports is seen by the idempotency check.
        $api = fn (callable $handler): \Closure => function (Request $request, Scenario $scenario, array $parameters) use ($handler): Response {
            $stripe = new StripeApi($scenario);

            return $stripe->handle($request, fn (array $input, array $parameters) => $handler($stripe, $input, $parameters), $parameters);
        };

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

    /**
     * The drift script against Stripe's test mode (StripeRecording), with a restricted test key from STRIPE_RECORD_KEY.
     */
    public function recordLive(Recorder $recorder): void
    {
        (new StripeRecording)->record($recorder);
    }

    public function sign(Scenario $scenario, string $body, int $timestamp, bool $wrongSecret = false): array
    {
        $secret = $wrongSecret ? 'whsec_NotTheSecretTheAppKnows000000000' : self::WEBHOOK_SECRET;

        return ['Stripe-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)];
    }

    public function actions(): array
    {
        return [
            // The event destination. `enabled_events` (optional) limits which event types it receives, as a Stripe
            // event destination does; left out, every event is sent.
            'webhook_url' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'webhook_url', (string) $input['url']);
                $events = is_array($input['enabled_events'] ?? null) ? array_values(array_map('strval', $input['enabled_events'])) : [];
                $scenario->state()->put('config', 'enabled_events', $events);

                return ['webhook_url' => $input['url'], 'enabled_events' => $events];
            },
            // The cardholder disputes a payment with their bank. A chargeback (the default) withdraws the disputed
            // amount plus the dispute fee from the balance at once, as one balance transaction on the dispute, and
            // Stripe sends charge.dispute.created and then charge.dispute.funds_withdrawn. An inquiry (`inquiry`:
            // true; status warning_needs_response) withdraws nothing, carries no balance transaction, sends only
            // charge.dispute.created, and leaves the charge refundable (docs.stripe.com/disputes/how-disputes-work).
            'dispute' => function (array $input, Scenario $scenario): array {
                $stripe = new StripeApi($scenario);
                $intent = $stripe->find((string) $input['payment_intent']);
                $charge = is_array($intent) ? $stripe->find((string) $intent['latest_charge']) : null;

                if ($charge === null) {
                    throw new \InvalidArgumentException('No paid PaymentIntent '.$input['payment_intent'].'.');
                }

                $inquiry = (bool) ($input['inquiry'] ?? false);
                $amount = (int) ($input['amount'] ?? $charge['amount']);
                $withdrawal = $inquiry ? null : self::disputeBalanceTransaction($stripe, -$amount, $charge['currency'], self::DISPUTE_FEE);
                $dispute = [
                    'id' => $stripe->newId('du_'),
                    'object' => 'dispute',
                    'amount' => $amount,
                    'balance_transactions' => $withdrawal === null ? [] : [$withdrawal],
                    'charge' => $charge['id'],
                    'created' => time(),
                    'currency' => $charge['currency'],
                    'is_charge_refundable' => $inquiry,
                    'livemode' => false,
                    'metadata' => [],
                    'payment_intent' => $charge['payment_intent'],
                    'reason' => (string) ($input['reason'] ?? ($inquiry ? 'general' : 'fraudulent')),
                    'status' => $inquiry ? 'warning_needs_response' : 'needs_response',
                ];
                $scenario->state()->put('disputes', $dispute['id'], $dispute);

                if ($withdrawal !== null) {
                    $scenario->state()->put('balance', $withdrawal['id'], $withdrawal);
                    $scenario->state()->put('charges', $charge['id'], [...$charge, 'disputed' => true]);
                }

                $stripe->emit('charge.dispute.created', $dispute);

                if ($withdrawal !== null) {
                    $stripe->emit('charge.dispute.funds_withdrawn', $dispute);
                }

                return ['dispute' => $dispute['id']];
            },
            // The issuer escalates an inquiry to a chargeback: the same dispute becomes `needs_response`, the disputed
            // amount plus the dispute fee is withdrawn as a balance transaction on it, and Stripe sends
            // charge.dispute.funds_withdrawn. The charge can no longer be refunded outside the dispute.
            'escalate_dispute' => function (array $input, Scenario $scenario): array {
                $stripe = new StripeApi($scenario);
                $dispute = $stripe->find((string) $input['dispute']);

                if ($dispute === null || $dispute['object'] !== 'dispute') {
                    throw new \InvalidArgumentException('No dispute '.$input['dispute'].'.');
                }

                if (! in_array($dispute['status'], ['warning_needs_response', 'warning_under_review'], true)) {
                    throw new \InvalidArgumentException("A {$dispute['status']} dispute can't escalate.");
                }

                // State is stored as decoded JSON; the dispute action wrote the amount as an int.
                $amount = (int) $dispute['amount'];
                $withdrawal = self::disputeBalanceTransaction($stripe, -$amount, $dispute['currency'], self::DISPUTE_FEE);
                $dispute = [...$dispute, 'status' => 'needs_response', 'is_charge_refundable' => false, 'balance_transactions' => [...$dispute['balance_transactions'], $withdrawal]];
                $scenario->state()->put('balance', $withdrawal['id'], $withdrawal);
                $scenario->state()->put('disputes', $dispute['id'], $dispute);
                $charge = $stripe->find((string) $dispute['charge']);

                if (is_array($charge)) {
                    $scenario->state()->put('charges', $charge['id'], [...$charge, 'disputed' => true]);
                }

                $stripe->emit('charge.dispute.funds_withdrawn', $dispute);

                return ['dispute' => $dispute['id'], 'status' => $dispute['status']];
            },
            // The dispute's outcome: an inquiry closes as warning_closed (nothing moves); a chargeback is lost (nothing
            // moves) or won (the amount is reinstated, charge.dispute.funds_reinstated, then charge.dispute.closed).
            'close_dispute' => function (array $input, Scenario $scenario): array {
                $stripe = new StripeApi($scenario);
                $dispute = $stripe->find((string) $input['dispute']);
                $status = (string) ($input['status'] ?? '');

                if ($dispute === null || $dispute['object'] !== 'dispute') {
                    throw new \InvalidArgumentException('No dispute '.$input['dispute'].'.');
                }

                $inquiry = str_starts_with((string) $dispute['status'], 'warning_');

                if ($inquiry ? $status !== 'warning_closed' : ! in_array($status, ['won', 'lost'], true)) {
                    throw new \InvalidArgumentException("A {$dispute['status']} dispute can't close as {$status}.");
                }

                $dispute = [...$dispute, 'status' => $status, 'is_charge_refundable' => false];

                if ($status === 'won') {
                    $reinstated = self::disputeBalanceTransaction($stripe, $dispute['amount'], $dispute['currency'], 0);
                    $scenario->state()->put('balance', $reinstated['id'], $reinstated);
                    $dispute['balance_transactions'][] = $reinstated;
                }

                $scenario->state()->put('disputes', $dispute['id'], $dispute);

                if ($status === 'won') {
                    $stripe->emit('charge.dispute.funds_reinstated', $dispute);
                }

                $stripe->emit('charge.dispute.closed', $dispute);

                return ['dispute' => $dispute['id'], 'status' => $status];
            },
        ];
    }

    /**
     * A balance transaction that moves dispute money: negative for the withdrawal (amount plus fee), positive for a
     * reinstatement, in Stripe's shape (reporting_category dispute, type adjustment).
     *
     * @return array<string, mixed>
     */
    private static function disputeBalanceTransaction(StripeApi $stripe, int $amount, string $currency, int $fee): array
    {
        return [
            'id' => $stripe->newId('txn_'),
            'object' => 'balance_transaction',
            'amount' => $amount,
            'available_on' => time(),
            'created' => time(),
            'currency' => $currency,
            'description' => $amount < 0 ? 'Chargeback withdrawal' : 'Chargeback reversal',
            'fee' => $fee,
            'net' => $amount - $fee,
            'reporting_category' => 'dispute',
            'status' => 'available',
            'type' => 'adjustment',
        ];
    }
}
