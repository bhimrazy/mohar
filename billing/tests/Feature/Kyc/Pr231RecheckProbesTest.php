<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Support\Facades\DB;

/**
 * Re-review probe for #231 at 96380c5c. Since StoreDocument now locks the current document (#228 M6), the adopted B2
 * probe passes with or without DecideDocument's client lock. This variant pauses the upload right after its client
 * lock, before it locks the document, so the decision takes the document first: without the client-first lock in
 * DecideDocument it deadlocks (40P01); with it the decision waits and is refused.
 */

/**
 * runRaceWorkers() (tests/Feature/Billing/Support/race.php) with this module's worker script.
 *
 * @param  array<string, list<string>>  $workers
 * @return array<string, array<string, mixed>>
 */
function kycEarlyRaceWorkers(array $workers, float $timeoutSeconds = 30.0): array
{
    $script = __DIR__.'/Support/kyc-race-worker-early.php';
    $running = [];

    try {
        foreach ($workers as $label => $arguments) {
            $process = proc_open([PHP_BINARY, $script, $label, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expect($process)->not->toBeFalse("worker $label did not start");
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[$label] = ['process' => $process, 'pipes' => $pipes, 'out' => '', 'err' => ''];
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $open = false;

            foreach ($running as &$worker) {
                $worker['out'] .= (string) stream_get_contents($worker['pipes'][1]);
                $worker['err'] .= (string) stream_get_contents($worker['pipes'][2]);
                $open = $open || ! feof($worker['pipes'][1]) || ! feof($worker['pipes'][2]);
            }
            unset($worker);

            if (! $open) {
                break;
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException('kyc race workers still running after '.$timeoutSeconds.' s');
            }

            usleep(20_000);
        }
    } finally {
        foreach ($running as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process'], 9);
            }

            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
        }
    }

    $results = [];

    foreach ($running as $label => $worker) {
        $result = json_decode(trim($worker['out']), true);
        expect($result)->toBeArray("worker $label printed no result: ".$worker['out'].$worker['err'])
            ->and(array_key_exists('error', $result))->toBeFalse("worker $label failed: ".($result['error'] ?? '').' '.$worker['err']);
        $results[$label] = $result;
    }

    return $results;
}

describe('approve versus re-upload in real concurrent transactions (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('B2b: a document is never both approved by staff and superseded by the client\'s replacement', function () {
        $this->useSeededDatabase('kyc_race_early', function (): void {
            $client = Client::factory()->create(['email' => 'kyc-race@example.test']);
            KycDocument::factory()->create(['client_id' => $client->id]);
            User::factory()->staff(['*'])->create(['email' => 'kyc-race-staff@example.test']);
        });
        $client = Client::query()->where('email', 'kyc-race@example.test')->sole();
        $document = KycDocument::query()->where('client_id', $client->id)->sole();
        $staff = User::query()->where('email', 'kyc-race-staff@example.test')->sole();
        $database = DB::connection()->getDatabaseName();

        // "upload" locks the client and pauses 1.5 s before locking the document; "decide" starts 0.5 s later. Without
        // the client lock in DecideDocument it locks the document, then its activity_log insert (FK to clients) waits for
        // the upload, whose document lock waits for the decision: 40P01. With it, the decision waits and is refused.
        $results = kycEarlyRaceWorkers([
            'upload' => ['upload', $database, (string) $client->id, '0', '1500'],
            'decide' => ['decide', $database, (string) $document->id, (string) $staff->id, '500'],
        ]);

        $old = KycDocument::query()->whereKey($document->id)->first();

        // One wins: approved and still the current document (the upload refused), or replaced (the decision refused).
        expect([$results['upload']['outcome'], $results['decide']['outcome']])->not->toBe(['stored', 'approved'])
            ->and($results['decide']['outcome'] === 'approved' ? $old?->superseded_at : null)->toBeNull()
            ->and($results['decide']['outcome'] === 'approved' ? $old?->status : DocumentStatus::Approved)->toBe(DocumentStatus::Approved);
    });
});
