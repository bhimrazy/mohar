<?php

use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Providers\Stripe\StripeSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Drift detection against real Stripe: every `source: live` recording in `stripe/recordings` (its `context` holds the
 * method, path and parameters of the call) is replayed against the simulator, and the answer must have the recording's
 * key paths and types. Skipped until a recording run exists: it needs a long-lived restricted Stripe test key, provided
 * by the owner as a GitHub or Codespaces secret (the sandbox key used on 2026-10-20 expires within hours).
 */
$recordings = array_values(array_filter(
    glob(dirname(__DIR__, 2).'/stripe/recordings/*.json') ?: [],
    fn (string $file): bool => (json_decode((string) file_get_contents($file), true)['source'] ?? null) === 'live',
));

test('the simulator answers every live Stripe recording in its shape', function () use ($recordings) {
    $scenario = Processes::start(StripeSimulator::class);

    try {
        foreach ($recordings as $file) {
            $recording = json_decode((string) file_get_contents($file), true);
            $call = $recording['context'] ?? throw new RuntimeException(basename($file).' does not say which call made it.');
            $request = Http::withOptions(['verify' => $scenario->ca()->bundlePath()])->withToken(StripeSimulator::SECRET_KEY)->asForm();
            $answer = strtoupper((string) $call['method']) === 'GET'
                ? $request->get($scenario->apiUrl().$call['path'], (array) json_decode((string) ($call['params'] ?? '[]'), true))
                : $request->post($scenario->apiUrl().$call['path'], (array) json_decode((string) ($call['params'] ?? '[]'), true));

            expect(Recorder::againstRecording($recording, (array) $answer->json()))->toBe([], basename($file));
        }
    } finally {
        Processes::stop($scenario);
    }
})->skip($recordings === [], 'No live Stripe recordings yet: a recording run needs a long-lived restricted test key from the owner (a GitHub or Codespaces secret).');
