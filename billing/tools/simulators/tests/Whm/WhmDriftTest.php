<?php

use App\Modules\Services\Drivers\Provisioning\Cpanel\WhmReasons;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Providers\Whm\LiveRecording;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Drift detection: every `source: live` recording of a real WHM (`simulate:record whm`) is replayed against the
 * simulator with the same call, and the answer must have the recording's key paths and types, and the same refusal
 * kind. The fixtures and the simulator come from one reading of the reference; only live recordings check it.
 */
$recordings = array_values(array_filter(
    glob(dirname(__DIR__, 2).'/whm/recordings/*.json') ?: [],
    fn (string $file): bool => (json_decode((string) file_get_contents($file), true)['source'] ?? null) === 'live',
));

test('the simulator answers every live WHM recording in its shape and with its refusal kind', function () use ($recordings) {
    $scenario = Processes::start(WhmSimulator::class);

    try {
        // The recording ran as root, or as the reseller its acctcounts names; the replay uses the matching login.
        $calls = LiveRecording::calls(WhmSimulator::ROOT_TOKEN, 'root', 'alpha') + ['acctcounts' => ['acctcounts', ['user' => 'reseller1']]];

        foreach ($recordings as $file) {
            $name = basename($file, '.json');
            $recording = json_decode((string) file_get_contents($file), true);
            [$function, $params] = $calls[$name] ?? throw new RuntimeException("No replay for the recording {$name}.");
            [$user, $token] = $name === 'acctcounts' ? ['reseller1', WhmSimulator::RESELLER_TOKEN] : ['root', WhmSimulator::ROOT_TOKEN];
            $answer = Http::withOptions(['verify' => $scenario->ca()->bundlePath()])->withHeaders(['Authorization' => "whm {$user}:{$token}"])
                ->asForm()->post($scenario->apiUrl()."/json-api/{$function}?api.version=1", $params)->json();
            $reason = fn (array $body): string => WhmReasons::classify((string) ($body['metadata']['reason'] ?? ''));

            expect(Recorder::againstRecording($recording, LiveRecording::anonymise((array) $answer)))->toBe([], $name)
                ->and($answer['metadata']['result'] ?? null)->toBe($recording['response']['metadata']['result'] ?? null, $name)
                ->and($reason((array) $answer))->toBe($reason((array) $recording['response']), $name);
        }
    } finally {
        Processes::stop($scenario);
    }
})->skip($recordings === [], 'No live WHM recordings yet: run `php artisan simulate:record whm` against a real server, and this test checks the simulator against them.');
