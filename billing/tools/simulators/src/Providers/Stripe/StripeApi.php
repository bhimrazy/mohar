<?php

namespace Mohar\Simulators\Providers\Stripe;

use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;
use Mohar\Simulators\Kernel\StateStore;

/**
 * Stripe's REST API (`/v1`) for what the PaymentsStripe driver calls, over the scenario state. Objects are stored as
 * Stripe returns them, with related objects as ids; `expand[]` replaces ids along a dotted path.
 *
 * - Every request needs `Authorization: Bearer <the scenario's secret key>`.
 * - A `Stripe-Version` must be one the simulator knows.
 * - A POST with an `Idempotency-Key` replays its first answer, and a different body for the same key is refused. As
 *   at Stripe, a request that failed parameter validation before it started executing saves no result, so the
 *   corrected retry with the key runs; a refusal after it started (`charge_already_refunded`) is saved and replayed.
 * - Parameters are checked as Stripe checks them: three-letter currencies, at most 50 metadata keys of up to 40
 *   characters with values up to 500, and `expand[]` only on properties that hold a related object.
 * - A Checkout Session past its `expires_at` expires on its own (read lazily), with its `checkout.session.expired`.
 *
 * Errors use Stripe's `{"error": {type, code, message, param}}` envelope, in our own words.
 */
final class StripeApi
{
    public const VERSIONS = ['2026-09-30.endive'];

    /** Id prefix => the state namespace holding those objects. */
    private const COLLECTIONS = ['cs' => 'sessions', 'pi' => 'payment_intents', 'ch' => 'charges', 'txn' => 'balance', 're' => 'refunds', 'du' => 'disputes', 'pm' => 'methods'];

    private readonly StateStore $state;

    /** Set when the request failed parameter validation, before anything ran: no idempotent result is saved. */
    private bool $invalidParameters = false;

    public function __construct(private readonly Scenario $scenario)
    {
        $this->state = $scenario->state();
    }

    /**
     * @param  callable(array<array-key, mixed>, array<string, string>): Response  $handler  input, path parameters
     * @param  array<string, string>  $parameters
     */
    public function handle(Request $request, callable $handler, array $parameters = []): Response
    {
        if (! hash_equals('Bearer '.StripeSimulator::SECRET_KEY, (string) $request->header('authorization'))) {
            return $this->error(401, 'invalid_request_error', 'Invalid API Key provided.', code: 'api_key_invalid');
        }

        $version = $request->header('stripe-version');

        if ($version !== null && ! in_array($version, self::VERSIONS, true)) {
            return $this->error(400, 'invalid_request_error', "Invalid Stripe API version: {$version}.", code: 'api_version_invalid');
        }

        $key = $request->header('idempotency-key');

        if ($request->method !== 'POST' || $key === null) {
            return $handler($request->input(), $parameters);
        }

        // One transaction: two workers with the same key cannot both run the request.
        return $this->state->transaction(function () use ($request, $handler, $parameters, $key): Response {
            $fingerprint = hash('sha256', $request->path."\n".$request->body);
            $stored = $this->state->get('idempotency', $key);

            if (is_array($stored)) {
                return $stored['fingerprint'] === $fingerprint
                    ? new Response((int) $stored['status'], ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true'], (string) $stored['body'])
                    : $this->error(400, 'idempotency_error', "The idempotency key {$key} was first used with other parameters; use a new key for a new request.");
            }

            $response = $handler($request->input(), $parameters);

            if (! $this->invalidParameters) {
                $this->state->put('idempotency', $key, ['fingerprint' => $fingerprint, 'status' => $response->status, 'body' => $response->body]);
            }

            return $response;
        });
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function createSession(array $input): Response
    {
        $item = $input['line_items'][0] ?? null;
        $price = is_array($item) ? ($item['price_data'] ?? null) : null;
        $amount = is_array($price) && is_numeric($price['unit_amount'] ?? null) ? (int) $price['unit_amount'] * max(1, (int) ($item['quantity'] ?? 1)) : 0;
        $currency = is_array($price) ? strtolower((string) ($price['currency'] ?? '')) : '';
        $expiresAt = (int) ($input['expires_at'] ?? time() + 86_400);

        $refusal = match (true) {
            ($input['mode'] ?? null) !== 'payment' => ['mode', 'This simulator supports Checkout in payment mode only.'],
            ! is_string($input['success_url'] ?? null) || $input['success_url'] === '' => ['success_url', 'A success_url is required in payment mode.'],
            ! is_array($price) || $currency === '' || count((array) $input['line_items']) !== 1 => ['line_items', 'Send one line item with price_data (currency, unit_amount, product_data).'],
            $amount < 50 => ['line_items[0][price_data][unit_amount]', 'The amount must be at least 50 in the smallest currency unit.'],
            $expiresAt < time() + 1_800 || $expiresAt > time() + 86_400 => ['expires_at', 'expires_at must be between 30 minutes and 24 hours from now.'],
            preg_match('/^[a-z]{3}$/', $currency) !== 1 => ['line_items[0][price_data][currency]', "Invalid currency: {$currency}. Use a three-letter ISO code in lower case."],
            ($metadata = $this->metadataProblem((array) ($input['metadata'] ?? []))) !== null => ['metadata', $metadata],
            default => null,
        };

        if ($refusal !== null) {
            $this->invalidParameters = true;

            return $this->error(400, 'invalid_request_error', $refusal[1], param: $refusal[0], code: $refusal[0] === 'line_items[0][price_data][unit_amount]' ? 'amount_too_small' : 'parameter_invalid');
        }

        $id = $this->newId('cs_test_');
        $session = [
            'id' => $id,
            'object' => 'checkout.session',
            'amount_subtotal' => $amount,
            'amount_total' => $amount,
            'client_reference_id' => $input['client_reference_id'] ?? null,
            'currency' => $currency,
            'customer' => null,
            'customer_email' => $input['customer_email'] ?? null,
            'expires_at' => $expiresAt,
            'livemode' => false,
            'metadata' => (array) ($input['metadata'] ?? []),
            'mode' => 'payment',
            'payment_intent' => null,
            'payment_status' => 'unpaid',
            'status' => 'open',
            'ui_mode' => 'hosted_page',
            'url' => $this->scenario->apiUrl()."/c/pay/{$id}#".bin2hex(random_bytes(8)),
        ];
        $this->state->put('sessions', $id, $session);
        // What the hosted page needs and the API never returns in this trimmed shape.
        $this->state->put('checkout', $id, [
            'success_url' => (string) $input['success_url'],
            'cancel_url' => (string) ($input['cancel_url'] ?? $input['success_url']),
            'description' => (string) ($price['product_data']['name'] ?? ''),
            'intent_data' => (array) ($input['payment_intent_data'] ?? []),
        ]);

        return Response::json($session);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, string>  $parameters
     */
    public function expireSession(array $input, array $parameters): Response
    {
        $session = $this->session($parameters['id']);

        if (! is_array($session)) {
            return $this->missing('checkout.session', $parameters['id']);
        }

        if ($session['status'] !== 'open') {
            return $this->error(400, 'invalid_request_error', "Only an open Checkout Session can be expired; this one is {$session['status']}.", code: 'checkout_session_not_open');
        }

        $session = [...$session, 'status' => 'expired', 'url' => null];
        $this->state->put('sessions', $session['id'], $session);
        $this->emit('checkout.session.expired', $session);

        return Response::json($session);
    }

    /**
     * Refund a PaymentIntent's charge, all of what is left or `amount` of it.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function createRefund(array $input): Response
    {
        $intent = $this->state->get('payment_intents', (string) ($input['payment_intent'] ?? ''));
        $charge = is_array($intent) ? $this->state->get('charges', (string) $intent['latest_charge']) : null;

        if (! is_array($intent) || ! is_array($charge) || $intent['status'] !== 'succeeded') {
            return $this->missing('payment_intent', (string) ($input['payment_intent'] ?? ''), 'payment_intent');
        }

        $left = $charge['amount'] - $charge['amount_refunded'];
        $amount = isset($input['amount']) ? (int) $input['amount'] : $left;

        if ($charge['disputed'] ?? false) {
            return $this->error(400, 'invalid_request_error', "Charge {$charge['id']} has been disputed and cannot be refunded; respond to the dispute instead.", code: 'charge_disputed');
        }

        if ($left <= 0) {
            return $this->error(400, 'invalid_request_error', "Charge {$charge['id']} has already been refunded.", code: 'charge_already_refunded');
        }

        if ($amount <= 0 || $amount > $left) {
            return $this->error(400, 'invalid_request_error', "Refund amount ({$amount}) is greater than the unrefunded amount on the charge ({$left}).", param: 'amount', code: 'amount_too_large');
        }

        $refund = [
            'id' => $this->newId('re_'),
            'object' => 'refund',
            'amount' => $amount,
            'balance_transaction' => $this->newId('txn_'),
            'charge' => $charge['id'],
            'created' => time(),
            'currency' => $charge['currency'],
            'metadata' => (array) ($input['metadata'] ?? []),
            'payment_intent' => $intent['id'],
            'reason' => $input['reason'] ?? null,
            'status' => 'succeeded',
        ];
        $charge = [...$charge, 'amount_refunded' => $charge['amount_refunded'] + $amount, 'refunded' => $charge['amount'] === $charge['amount_refunded'] + $amount];
        $this->state->put('refunds', $refund['id'], $refund);
        $this->state->put('charges', $charge['id'], $charge);
        $this->emit('refund.created', $refund);
        $this->emit('charge.refunded', $charge);

        return Response::json($refund);
    }

    /**
     * Any stored object by id, with `expand[]`.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function retrieve(string $type, string $id, array $input): Response
    {
        $object = $type === 'checkout.session' ? $this->session($id) : $this->find($id);

        if ($object === null || $object['object'] !== $type) {
            return $this->missing($type, $id);
        }

        foreach ((array) ($input['expand'] ?? []) as $path) {
            $expanded = $this->expand($object, explode('.', (string) $path));

            if ($expanded === null) {
                return $this->error(400, 'invalid_request_error', "This property cannot be expanded ({$path}).", param: 'expand');
            }

            $object = $expanded;
        }

        return Response::json($object);
    }

    /**
     * GET /v1/refunds: the refunds of one PaymentIntent (the only filter used), newest first.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function listRefunds(array $input): Response
    {
        $intent = (string) ($input['payment_intent'] ?? '');
        $limit = max(1, min(100, (int) ($input['limit'] ?? 10)));
        $refunds = array_values(array_filter((array) $this->state->all('refunds'), fn (mixed $refund): bool => is_array($refund) && ($intent === '' || $refund['payment_intent'] === $intent)));
        usort($refunds, fn (array $a, array $b): int => [$b['created'], $b['id']] <=> [$a['created'], $a['id']]);

        return Response::json(['object' => 'list', 'url' => '/v1/refunds', 'has_more' => count($refunds) > $limit, 'data' => array_slice($refunds, 0, $limit)]);
    }

    public function account(): Response
    {
        return Response::json((array) $this->state->get('config', 'account'));
    }

    /**
     * Record an event for an object as it is now, and queue its signed delivery to the app's webhook endpoint when the
     * destination is subscribed to its type.
     *
     * @param  array<string, mixed>  $object
     */
    public function emit(string $type, array $object): void
    {
        $event = [
            'id' => $this->newId('evt_'),
            'object' => 'event',
            'api_version' => self::VERSIONS[0],
            'created' => time(),
            'data' => ['object' => $object],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => $type,
        ];
        $this->state->put('events', $event['id'], $event);
        $url = $this->state->get('config', 'webhook_url');
        $enabled = $this->state->get('config', 'enabled_events');

        if (is_string($url) && $url !== '' && (! is_array($enabled) || $enabled === [] || in_array($type, $enabled, true))) {
            $this->scenario->outbox()->enqueue($url, (string) json_encode($event, JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json; charset=utf-8']);
        }
    }

    /**
     * A Checkout Session as it is now: an open one past its `expires_at` is expired first (with its event), as Stripe
     * expires sessions on its own.
     *
     * @return array<string, mixed>|null
     */
    public function session(string $id): ?array
    {
        return $this->state->transaction(function () use ($id): ?array {
            $session = $this->state->get('sessions', $id);

            if (! is_array($session)) {
                return null;
            }

            if ($session['status'] === 'open' && $session['expires_at'] <= time()) {
                $session = [...$session, 'status' => 'expired', 'url' => null];
                $this->state->put('sessions', $id, $session);
                $this->emit('checkout.session.expired', $session);
            }

            return $session;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $prefix = strstr($id, '_', true);
        $object = isset(self::COLLECTIONS[$prefix]) ? $this->state->get(self::COLLECTIONS[$prefix], $id) : null;

        return is_array($object) ? $object : null;
    }

    public function newId(string $prefix): string
    {
        return $prefix.'3Sim'.substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(24))), 0, 20);
    }

    /**
     * The object with the related object at $path in place of its id; null when a property on the path does not hold
     * a related object (Stripe refuses to expand it). A related object not set yet (null) stays null.
     *
     * @param  array<string, mixed>  $object
     * @param  list<string>  $path
     * @return array<string, mixed>|null
     */
    private function expand(array $object, array $path): ?array
    {
        $field = array_shift($path);

        if (! array_key_exists($field, $object)) {
            return null;
        }

        $value = $object[$field];

        if ($value === null) {
            return $object;
        }

        $related = is_string($value) ? $this->find($value) : (is_array($value) && isset($value['object']) ? $value : null);

        if ($related === null) {
            return null;
        }

        $expanded = $path === [] ? $related : $this->expand($related, $path);

        if ($expanded === null) {
            return null;
        }

        $object[$field] = $expanded;

        return $object;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     */
    private function metadataProblem(array $metadata): ?string
    {
        if (count($metadata) > 50) {
            return 'Metadata can hold at most 50 keys.';
        }

        foreach ($metadata as $key => $value) {
            if (strlen((string) $key) > 40 || strlen(is_scalar($value) ? (string) $value : '') > 500) {
                return 'Metadata keys can be up to 40 characters and values up to 500.';
            }
        }

        return null;
    }

    private function missing(string $type, string $id, string $param = 'id'): Response
    {
        return $this->error(404, 'invalid_request_error', "No such {$type}: '{$id}'", param: $param, code: 'resource_missing');
    }

    private function error(int $status, string $type, string $message, ?string $param = null, ?string $code = null): Response
    {
        return Response::json(['error' => array_filter(['code' => $code, 'message' => $message, 'param' => $param, 'type' => $type], fn (?string $value): bool => $value !== null)], $status);
    }
}
