<?php

use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Providers\Stripe\StripeRecording;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Drift detection against real Stripe: the recording script (StripeRecording, `simulate:record stripe`) is replayed as
 * the same sequence against the simulator, and each `source: live` recording must match on the paths the driver reads.
 *
 * Recording is deferred (CTO decision on #113, ADR 0016): it needs a long-lived restricted Stripe test key as a GitHub
 * or Codespaces secret, an owner action listed in docs/handoff/state.md. A recording file that does not parse is not
 * skipped: it fails the test.
 */
$recordings = array_values(array_filter(
    glob(dirname(__DIR__, 2).'/stripe/recordings/*.json') ?: [],
    function (string $file): bool {
        $decoded = json_decode((string) file_get_contents($file), true);

        return ! is_array($decoded) || ($decoded['source'] ?? null) === 'live';
    },
));

/**
 * @param  list<string>  $files
 * @return array<string, list<string>> step => drift
 */
function stripeDrift(array $files): array
{
    $scenario = Processes::start(StripeSimulator::class);

    try {
        $answers = StripeRecording::replay($scenario);
        $drift = [];

        foreach ($files as $file) {
            $recording = json_decode((string) file_get_contents($file), true) ?? throw new RuntimeException(basename($file).' is not valid JSON.');
            $step = (string) ($recording['context']['step'] ?? throw new RuntimeException(basename($file).' does not say which step made it.'));
            $drift[$step] = StripeRecording::drift($recording, $answers[$step] ?? throw new RuntimeException("No step {$step} in the recording script."));
        }

        return $drift;
    } finally {
        Processes::stop($scenario);
    }
}

test('the simulator answers every live Stripe recording in the shape the driver reads', function () use ($recordings) {
    expect(array_filter(stripeDrift($recordings)))->toBe([]);
})->skip($recordings === [], 'Recording deferred (CTO decision, #113): it needs a long-lived restricted Stripe test key as a GitHub or Codespaces secret from the owner.');

test('a realistic live answer, normalised, passes; only a change on a path the driver reads is drift', function () {
    $directory = sys_get_temp_dir().'/stripe-drift-'.bin2hex(random_bytes(4));
    $recorder = new Recorder($directory);
    $fixture = fn (string $name): array => json_decode((string) file_get_contents(base_path("tests/Fixtures/Stripe/{$name}.json")), true);
    // As Stripe answers: real-looking ids and absolute times, and many fields the simulator does not model.
    $live = [...$fixture('checkout_session_created'), 'id' => 'cs_test_a1B2c3D4e5F6g7H8i9J0kLmNoPqRsTuV', 'created' => 1_792_300_000, 'expires_at' => 1_792_302_100,
        'automatic_tax' => ['enabled' => false, 'liability' => null, 'status' => null], 'custom_text' => ['submit' => null], 'locale' => null, 'payment_method_types' => ['card'],
        'success_url' => 'https://shop.example.test/return?ref=drift-1', 'total_details' => ['amount_discount' => 0, 'amount_tax' => 0], 'metadata' => ['mohar_reference' => 'drift-1', 'mohar_flow' => 'checkout']];
    $missing = [...$fixture('error_resource_missing'), 'error' => [...$fixture('error_resource_missing')['error'], 'doc_url' => 'https://stripe.com/docs/error-codes/resource-missing', 'request_log_url' => 'https://dashboard.stripe.com/test/logs/req_x']];

    try {
        $recorder->record('create_session', StripeRecording::normalise($live), 'live', [], ['step' => 'create_session', 'method' => 'POST', 'kind' => 'session']);
        $recorder->record('retrieve_missing', StripeRecording::normalise($missing), 'live', [], ['step' => 'retrieve_missing', 'method' => 'GET', 'kind' => 'error']);
        $saved = json_decode((string) file_get_contents($directory.'/create_session.json'), true);

        expect(stripeDrift(glob($directory.'/*.json') ?: []))->toBe(['create_session' => [], 'retrieve_missing' => []])
            ->and($saved['response']['id'])->toBe('cs_test_normalised')
            ->and($saved['response']['created'])->toBe(0)
            ->and(array_keys($saved['response']))->toBe(array_values(array_unique([...array_keys($saved['response'])])))
            ->and(StripeRecording::drift($saved, [...$saved['response'], 'livemode' => 'false']))->toBe(['livemode is string, recorded bool'])
            ->and(StripeRecording::drift($saved, array_diff_key($saved['response'], ['client_reference_id' => true])))->toBe(['missing client_reference_id'])
            ->and(StripeRecording::drift($saved, array_diff_key($saved['response'], ['automatic_tax' => true])))->toBe([]);
    } finally {
        array_map('unlink', glob($directory.'/*') ?: []);
        @rmdir($directory);
    }
});

test('the recording script replays end to end against the simulator, each step answering as Stripe would', function () {
    $scenario = Processes::start(StripeSimulator::class);

    try {
        $answers = StripeRecording::replay($scenario);
    } finally {
        Processes::stop($scenario);
    }

    expect(array_keys($answers))->toBe(array_keys(StripeRecording::steps()))
        ->and($answers['create_session']['status'])->toBe('open')
        ->and($answers['expire_session']['status'])->toBe('expired')
        ->and($answers['retrieve_missing']['error']['code'])->toBe('resource_missing')
        ->and($answers['amount_too_small']['error']['code'])->toBe('amount_too_small')
        ->and($answers['paid_intent']['status'])->toBe('succeeded')
        ->and($answers['retrieve_intent']['latest_charge']['balance_transaction']['fee'])->toBe(103)
        ->and($answers['partial_refund'])->toMatchArray(['status' => 'succeeded', 'amount' => 1_000])
        ->and($answers['retrieve_refund']['payment_intent']['id'])->toBe('pi_normalised')
        ->and($answers['rest_refund']['amount'])->toBe(1_500)
        ->and($answers['refund_again']['error']['code'])->toBe('charge_already_refunded');
});
