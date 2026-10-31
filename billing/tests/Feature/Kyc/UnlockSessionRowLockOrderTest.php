<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycReviewUnlock;
use App\Modules\Kyc\Services\ReviewUnlocks;
use Illuminate\Support\Facades\DB;

/*
 * Re-check 2 probes for PR #308 at 67065d30: one row per (staff, client, session). Two sessions of one login change
 * each other's rows (Hide and sign-out end the other sessions'), so their row locks must be taken in one order
 * (ADR 0021 §3: several rows of one table in ascending id, in one query).
 */

/**
 * @param  array<string, list<string>>  $workers
 * @return array<string, array<string, mixed>>
 */
function kycSessionWorkers(array $workers): array
{
    $running = [];

    foreach ($workers as $label => $arguments) {
        $running[$label] = proc_open([PHP_BINARY, __DIR__.'/Support/kyc-unlock-session-worker.php', $label, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $running[$label] = [$running[$label], $pipes];
    }

    $results = [];

    foreach ($running as $label => [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        proc_close($process);
        $results[$label] = json_decode(trim($out), true) ?? ['error' => $out];
    }

    return $results;
}

describe('two sessions of one login (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }

        $this->seed2 = function (string $name, bool $bOpen = true): void {
            $this->useSeededDatabase($name, function () use ($bOpen): void {
                $client = Client::factory()->create(['email' => 'kyc-two@example.test']);
                $staff = User::factory()->staff(['*'])->create(['email' => 'kyc-two-staff@example.test']);
                app(ReviewUnlocks::class)->open($staff->id, $client->id, now()->addMinutes(10), 0, str_repeat('a', 64));
                if ($bOpen) {
                    app(ReviewUnlocks::class)->open($staff->id, $client->id, now()->addMinutes(10), 0, str_repeat('b', 64));
                }
            });
            $this->c2 = Client::query()->where('email', 'kyc-two@example.test')->sole();
            $this->s2 = User::query()->where('email', 'kyc-two-staff@example.test')->sole();
            $this->args = fn (string $mode, string $who, string $generation, string $delay, string $hold): array => [DB::connection()->getDatabaseName(), $mode, (string) $this->s2->id, (string) $this->c2->id, str_repeat($who, 64), $generation, $delay, $hold];
            $this->openFor = fn (string $who): bool => app(ReviewUnlocks::class)->until($this->s2->id, $this->c2->id, str_repeat($who, 64)) !== null;
        };
    });

    test('D1: Hide in A and Hide in B at the same moment both apply, no deadlock, and both end', function () {
        ($this->seed2)('kyc_two_hide_hide');

        $results = kycSessionWorkers([
            'hideA' => ($this->args)('close', 'a', '0', '0', '600'),
            'hideB' => ($this->args)('close', 'b', '0', '100', '600'),
        ]);

        expect(json_encode($results))->not->toContain('"error"')
            ->and($results['hideB'])->not->toHaveKey('error')
            ->and(($this->openFor)('a'))->toBeFalse()
            ->and(($this->openFor)('b'))->toBeFalse();
    });

    test('D2: sign-out in A and Hide in B at the same moment: no deadlock, both end', function () {
        ($this->seed2)('kyc_two_signout_hide');

        $results = kycSessionWorkers([
            'signoutA' => ($this->args)('signout', 'a', '0', '0', '600'),
            'hideB' => ($this->args)('close', 'b', '0', '100', '600'),
        ]);

        expect(json_encode($results))->not->toContain('"error"')
            ->and($results['signoutA'])->not->toHaveKey('error')
            ->and($results['hideB'])->not->toHaveKey('error')
            ->and(($this->openFor)('a'))->toBeFalse()
            ->and(($this->openFor)('b'))->toBeFalse();
    });

    test('pin: Hide in A while Show in B holds B\'s row waits, then ends B as well (Show then Hide)', function () {
        ($this->seed2)('kyc_two_hide_show', bOpen: false);
        $generationB = (string) app(ReviewUnlocks::class)->generation($this->s2->id, $this->c2->id, str_repeat('b', 64));

        $results = kycSessionWorkers([
            'showB' => ($this->args)('open', 'b', $generationB, '0', '800'),
            'hideA' => ($this->args)('close', 'a', '0', '300', '0'),
        ]);

        // B's row did not exist before its Show, so Hide's scan may skip it (Hide first, then Show: B open) or wait
        // and end it (Show first, then Hide). Either is a serial order; neither may error.
        expect($results['showB'])->not->toHaveKey('error')
            ->and($results['hideA'])->not->toHaveKey('error')
            ->and(($this->openFor)('a'))->toBeFalse();
    });

    test('pin: the cleanup on Show keeps a row that is open or ended and not yet noticed while a session can still be alive, and drops one older', function () {
        ($this->seed2)('kyc_two_cleanup');
        KycReviewUnlock::query()->where('session_hash', str_repeat('b', 64))->update(['updated_at' => now()->subMinutes(20), 'expires_at' => now()->subMinutes(5)]);
        // The cutoff follows the session lifetime (plus an hour), not a fixed day (L7).
        $this->travel((int) config('session.lifetime') - 10)->minutes();
        $before = KycReviewUnlock::query()->count();

        app(ReviewUnlocks::class)->open($this->s2->id, $this->c2->id, now()->addMinutes(10), 1, str_repeat('a', 64));

        expect(KycReviewUnlock::query()->count())->toBe($before);

        $this->travel((int) config('session.lifetime') + 120)->minutes();
        app(ReviewUnlocks::class)->open($this->s2->id, $this->c2->id, now()->addMinutes(10), 2, str_repeat('a', 64));

        expect(KycReviewUnlock::query()->where('session_hash', str_repeat('b', 64))->exists())->toBeFalse();
    });
});
