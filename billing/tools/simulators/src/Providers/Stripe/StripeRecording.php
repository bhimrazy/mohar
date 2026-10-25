<?php

namespace Mohar\Simulators\Providers\Stripe;

use Closure;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Kernel\Scenario;
use RuntimeException;

/**
 * Drift detection against real Stripe (ADR 0016 §1, Recorder): one scripted sequence of calls, run against the Stripe
 * test-mode API by `simulate:record stripe` and replayed against the simulator by the drift test.
 *
 * - A step's parameters may name an earlier step's answer (`{create_session.id}`) and relative times (`+2100`), so a
 *   replay never sends a recorded id or an expired absolute time.
 * - What is not drift is normalised before anything is stored or compared: ids (prefix kept), timestamps, secrets,
 *   account-specific values, and key order.
 * - A recording stores and is compared on only the paths the driver reads (DRIVER_PATHS): a live answer carries many
 *   more fields, among them account and customer data, and an extra field is never drift. On error steps the error type
 *   and code are compared as values, since the driver branches on them.
 */
final class StripeRecording
{
    /**
     * The paths the PaymentsStripe driver reads, by kind of answer. A recording stores only these (`project()`), so it
     * holds no account or customer data to begin with, and drift is checked on exactly these. An object at a listed path
     * keeps only its own listed children, except the WHOLE ones (metadata the app itself sent).
     */
    public const DRIVER_PATHS = [
        'account' => ['id', 'object', 'business_profile', 'business_profile.name'],
        'session' => [
            'id', 'object', 'amount_total', 'client_reference_id', 'currency', 'expires_at', 'livemode', 'metadata', 'mode', 'payment_status', 'status', 'url',
            'payment_intent', 'payment_intent.id', 'payment_intent.status', 'payment_intent.amount_received', 'payment_intent.currency', 'payment_intent.metadata',
        ],
        'intent' => [
            'id', 'object', 'amount', 'amount_received', 'currency', 'livemode', 'metadata', 'status',
            'last_payment_error', 'last_payment_error.code', 'last_payment_error.message',
            'latest_charge', 'latest_charge.id', 'latest_charge.amount_refunded', 'latest_charge.refunded',
            'latest_charge.balance_transaction', 'latest_charge.balance_transaction.currency', 'latest_charge.balance_transaction.fee',
        ],
        'refund' => ['id', 'object', 'amount', 'currency', 'failure_reason', 'metadata', 'reason', 'status', 'payment_intent', 'payment_intent.id', 'payment_intent.metadata'],
        'error' => ['error', 'error.code', 'error.type'],
    ];

    /** Listed paths kept whole: the metadata the app itself sends. */
    private const WHOLE = ['metadata', 'payment_intent.metadata'];

    /** On an error step the driver branches on these values, so a different one is drift. */
    private const COMPARED_VALUES = ['error.code', 'error.type'];

    /** Keys whose integer values are moments in time. */
    private const TIME_KEYS = ['created', 'expires_at', 'available_on', 'arrival_date', 'canceled_at', 'completed_at'];

    /** Keys whose string values are specific to the account or the customer. */
    private const ACCOUNT_KEYS = ['name', 'email', 'customer_email', 'url', 'support_email', 'support_phone', 'support_url', 'statement_descriptor', 'description', 'receipt_email', 'country', 'display_name'];

    /**
     * The sequence: name => [method, path, params, kind]. `paid_intent` is a PaymentIntent paid with Stripe's test card;
     * the simulator, which only takes payments on its hosted page, pays a Checkout Session there instead.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function steps(): array
    {
        $session = fn (int $amount): array => [
            'mode' => 'payment',
            'success_url' => 'https://shop.example.test/return?ref=drift-1',
            'cancel_url' => 'https://shop.example.test/return?ref=drift-1&cancelled=1',
            'client_reference_id' => 'drift-1',
            'expires_at' => '+2100',
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $amount, 'product_data' => ['name' => 'Drift check']]]],
            'metadata' => ['mohar_reference' => 'drift-1', 'mohar_flow' => 'checkout'],
            'payment_intent_data' => ['metadata' => ['mohar_reference' => 'drift-1', 'mohar_flow' => 'checkout']],
        ];

        return [
            'account' => ['GET', '/v1/account', [], 'account'],
            'create_session' => ['POST', '/v1/checkout/sessions', $session(2_500), 'session'],
            'retrieve_session' => ['GET', '/v1/checkout/sessions/{create_session.id}', ['expand' => ['payment_intent']], 'session'],
            'expire_session' => ['POST', '/v1/checkout/sessions/{create_session.id}/expire', [], 'session'],
            'retrieve_missing' => ['GET', '/v1/checkout/sessions/cs_test_driftmissing000000', [], 'error'],
            'amount_too_small' => ['POST', '/v1/checkout/sessions', $session(10), 'error'],
            'paid_intent' => ['POST', '/v1/payment_intents', ['amount' => 2_500, 'currency' => 'usd', 'payment_method' => 'pm_card_visa', 'confirm' => 'true', 'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'], 'metadata' => ['mohar_reference' => 'drift-1', 'mohar_flow' => 'checkout']], 'intent'],
            'retrieve_intent' => ['GET', '/v1/payment_intents/{paid_intent.id}', ['expand' => ['latest_charge.balance_transaction']], 'intent'],
            'partial_refund' => ['POST', '/v1/refunds', ['payment_intent' => '{paid_intent.id}', 'amount' => 1_000], 'refund'],
            'retrieve_refund' => ['GET', '/v1/refunds/{partial_refund.id}', ['expand' => ['payment_intent']], 'refund'],
            'rest_refund' => ['POST', '/v1/refunds', ['payment_intent' => '{paid_intent.id}'], 'refund'],
            'refund_again' => ['POST', '/v1/refunds', ['payment_intent' => '{paid_intent.id}'], 'error'],
        ];
    }

    /**
     * `simulate:record stripe`: the sequence against Stripe's test mode, with a restricted test key from
     * STRIPE_RECORD_KEY only, recorded as `source: live`.
     */
    public function record(Recorder $recorder): void
    {
        $key = $_SERVER['STRIPE_RECORD_KEY'] ?? $_ENV['STRIPE_RECORD_KEY'] ?? getenv('STRIPE_RECORD_KEY');

        if (! is_string($key) || preg_match('/^(sk|rk)_test_[A-Za-z0-9]{8,}$/', $key) !== 1) {
            throw new RuntimeException('Set STRIPE_RECORD_KEY to a restricted Stripe test key (rk_test_… or sk_test_…) to record; live keys are refused.');
        }

        $run = bin2hex(random_bytes(4));
        $send = function (string $method, string $path, array $params, string $step) use ($key, $run): array {
            $request = Http::withToken($key)->withHeaders(['Stripe-Version' => StripeApi::VERSIONS[0]])->acceptJson()->timeout(30)->asForm();
            $response = $method === 'GET'
                ? $request->get('https://api.stripe.com'.$path, $params)
                : $request->withHeaders(['Idempotency-Key' => "drift-{$run}-{$step}"])->post('https://api.stripe.com'.$path, $params);

            return (array) $response->json();
        };

        foreach (self::run($send) as $step => [$answer, $context]) {
            $recorder->record($step, self::normalise(self::project($answer, $context['kind'])), 'live', [$key], $context);
        }
    }

    /**
     * The sequence against a running stripe scenario: name => the simulator's answer, normalised.
     *
     * @return array<string, array<array-key, mixed>>
     */
    public static function replay(Scenario $scenario): array
    {
        $api = Http::withOptions(['verify' => $scenario->ca()->bundlePath(), 'allow_redirects' => false])->withToken(StripeSimulator::SECRET_KEY)->acceptJson()->asForm();
        $send = function (string $method, string $path, array $params, string $step) use ($api, $scenario): array {
            if ($step === 'paid_intent') {
                [, , $session] = self::steps()['create_session'];
                $created = (array) $api->post($scenario->apiUrl().'/v1/checkout/sessions', self::resolveParams($session, []))->json();
                Http::withOptions(['verify' => $scenario->ca()->bundlePath(), 'allow_redirects' => false])->post($scenario->apiUrl()."/c/pay/{$created['id']}/pay");
                $path = '/v1/payment_intents/'.$scenario->state()->get('sessions', (string) $created['id'])['payment_intent'];
                $method = 'GET';
                $params = [];
            }

            $response = $method === 'GET' ? $api->get($scenario->apiUrl().$path, $params) : $api->post($scenario->apiUrl().$path, $params);

            return (array) $response->json();
        };

        return array_map(fn (array $pair): array => self::normalise(self::project($pair[0], $pair[1]['kind'])), self::run($send));
    }

    /**
     * Where an answer drifted from its recording: on the paths the driver reads for that kind of answer, a path the
     * recording has that the answer lacks, or a different type. Extra fields on either side are not drift.
     *
     * @param  array<string, mixed>  $recording  a recording file (`shape`, `response`, `context`)
     * @param  array<array-key, mixed>  $answer
     * @return list<string>
     */
    public static function drift(array $recording, array $answer): array
    {
        $kind = (string) ($recording['context']['kind'] ?? 'session');
        $answer = self::normalise(self::project($answer, $kind));
        $drift = array_values(array_filter(Recorder::againstRecording($recording, $answer), fn (string $difference): bool => ! str_starts_with($difference, 'extra ')));

        if ($kind === 'error') {
            foreach (self::COMPARED_VALUES as $path) {
                [$group, $field] = explode('.', $path);
                $recorded = $recording['response'][$group][$field] ?? null;
                $actual = $answer[$group][$field] ?? null;

                if ($recorded !== $actual) {
                    $drift[] = "{$path} is ".var_export($actual, true).', recorded '.var_export($recorded, true);
                }
            }
        }

        return $drift;
    }

    /**
     * Only the paths the driver reads for this kind of answer (DRIVER_PATHS), as they came.
     *
     * @param  array<array-key, mixed>  $answer
     * @return array<string, mixed>
     */
    public static function project(array $answer, string $kind): array
    {
        $paths = self::DRIVER_PATHS[$kind] ?? [];
        usort($paths, fn (string $a, string $b): int => substr_count($a, '.') <=> substr_count($b, '.'));
        $projected = [];

        foreach ($paths as $path) {
            $value = $answer;

            foreach (explode('.', $path) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    continue 2;
                }

                $value = $value[$segment];
            }

            $target = &$projected;
            $segments = explode('.', $path);
            $last = array_pop($segments);

            foreach ($segments as $segment) {
                if (! is_array($target[$segment] ?? null)) {
                    continue 2;
                }

                $target = &$target[$segment];
            }

            $target[$last] = is_array($value) && ! in_array($path, self::WHOLE, true) ? [] : $value;
            unset($target);
        }

        return $projected;
    }

    /**
     * The same structure with what is not drift replaced: ids keep their prefix, times become 0, account and customer
     * strings become `example`, and keys are sorted.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function normalise(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = match (true) {
                is_array($item) => self::normalise($item),
                is_int($item) && is_string($key) && in_array($key, self::TIME_KEYS, true) => 0,
                is_string($item) && preg_match('/^(acct|cs_test|cs_live|pi|ch|txn|re|du|pm|evt|py|src|cus|card|seti|in)_[A-Za-z0-9_]+$/', $item, $match) === 1 => $match[1].'_normalised',
                is_string($item) && is_string($key) && in_array($key, self::ACCOUNT_KEYS, true) => 'example',
                is_string($item) && str_contains($item, '_secret_') => 'secret_normalised',
                default => $item,
            };
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Run the sequence through $send(method, path, params, step): name => [answer, recording context].
     *
     * @param  Closure(string, string, array<string, mixed>, string): array<array-key, mixed>  $send
     * @return array<string, array{0: array<array-key, mixed>, 1: array<string, string>}>
     */
    private static function run(Closure $send): array
    {
        $answers = [];

        foreach (self::steps() as $step => [$method, $path, $params, $kind]) {
            $answers[$step] = [$send($method, self::resolveString($path, $answers), self::resolveParams($params, $answers), $step), ['step' => $step, 'method' => $method, 'kind' => $kind]];
        }

        return $answers;
    }

    /**
     * Parameters with `{step.path}` filled from earlier answers and `+seconds` turned into a time that far from now.
     *
     * @param  array<array-key, mixed>  $params
     * @param  array<string, array{0: array<array-key, mixed>, 1: array<string, string>}>  $answers
     * @return array<array-key, mixed>
     */
    private static function resolveParams(array $params, array $answers): array
    {
        return array_map(fn (mixed $item): mixed => match (true) {
            is_array($item) => self::resolveParams($item, $answers),
            is_string($item) => self::resolveString($item, $answers),
            default => $item,
        }, $params);
    }

    /**
     * @param  array<string, array{0: array<array-key, mixed>, 1: array<string, string>}>  $answers
     */
    private static function resolveString(string $value, array $answers): string
    {
        if (preg_match('/^\+(\d+)$/', $value, $match) === 1) {
            return (string) (time() + (int) $match[1]);
        }

        return (string) preg_replace_callback('/\{([a-z_]+)\.([a-z_.]+)\}/', function (array $match) use ($answers): string {
            $found = $answers[$match[1]][0] ?? [];

            foreach (explode('.', $match[2]) as $segment) {
                $found = is_array($found) ? ($found[$segment] ?? '') : '';
            }

            return is_scalar($found) ? (string) $found : '';
        }, $value);
    }
}
