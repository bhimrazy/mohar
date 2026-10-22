<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\ReviewUnlocks;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Support/race.php';

/*
 * Issue #257: the client learns how long the unlock still lasts from the server, not an absolute time to compare with
 * its own clock. Issue #258: unlocks live in the database, so no request that started before Hide documents can write
 * an old one back, and a stale Show request is refused by its generation.
 */

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    $this->client = Client::factory()->create();
    $this->document = KycDocument::factory()->create(['client_id' => $this->client->id, 'number' => '27-01-74-04821']);
    Storage::disk(KycDocument::DISK)->put($this->document->front_path, Crypt::encryptString('front-bytes'));
    $this->staff = actingAsStaff(['kyc.documents.view']);
    $this->show = fn () => $this->get(route('admin.kyc.show', $this->client))->assertOk();
});

test('#257: the page carries the seconds left, counted by the server, and nothing without an unlock', function () {
    ($this->show)()->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedFor', null));

    confirmPassword();
    $this->post(route('admin.kyc.unlock', $this->client))->assertRedirect();
    ($this->show)()->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedFor', 900));

    $this->travel(5)->minutes();
    ($this->show)()->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedFor', 600));

    $this->travel(11)->minutes();
    ($this->show)()->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedFor', null)->where('unlockedUntil', null));
});

test('#258: a session saved by a request that began before Hide documents does not bring the unlock back', function () {
    confirmPassword();
    $this->post(route('admin.kyc.unlock', $this->client))->assertRedirect();
    $before = session()->all();

    $this->post(route('admin.kyc.lock', $this->client))->assertRedirect();

    // The late request wrote its whole session payload, taken before the lock, over the newer one.
    $this->flushSession()->withSession($before);
    ($this->show)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('unlockedUntil', null)->where('documents.0.number', null)->where('documents.0.images', []));
    $this->get(route('admin.kyc.image', ['document' => $this->document->id, 'side' => 'front', 'staff' => $this->staff->id]))->assertForbidden();
});

test('#258: a Show request from before Hide documents is refused by its generation, a fresh one is not', function () {
    confirmPassword();
    $this->post(route('admin.kyc.unlock', $this->client))->assertRedirect();
    $generation = ($this->show)()->viewData('page')['props']['unlockGeneration'];

    $this->post(route('admin.kyc.lock', $this->client))->assertRedirect();
    $this->post(route('admin.kyc.unlock', $this->client), ['generation' => $generation])->assertRedirect();

    ($this->show)()->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedUntil', null)->where('documents.0.number', null));

    $fresh = ($this->show)()->viewData('page')['props']['unlockGeneration'];
    $this->post(route('admin.kyc.unlock', $this->client), ['generation' => $fresh])->assertRedirect();
    ($this->show)()->assertInertia(fn (AssertableInertia $page) => $page->whereNot('unlockedUntil', null));
});

describe('Show and Hide in real concurrent transactions (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }

        // One seeded database per test (committed, outside the test's transaction), with the documents open.
        $this->raceSeed = function (string $name): void {
            $this->useSeededDatabase($name, function (): void {
                $client = Client::factory()->create(['email' => 'kyc-unlock@example.test']);
                $staff = User::factory()->staff(['*'])->create(['email' => 'kyc-unlock-staff@example.test']);
                app(ReviewUnlocks::class)->open($staff->id, $client->id, now()->addMinutes(10));
            });
            $this->raceClient = Client::query()->where('email', 'kyc-unlock@example.test')->sole();
            $this->raceStaff = User::query()->where('email', 'kyc-unlock-staff@example.test')->sole();
            $this->raceGeneration = (string) app(ReviewUnlocks::class)->generation($this->raceStaff->id, $this->raceClient->id);
        };
        $this->raceRun = fn (array $workers): array => kycRaceWorkers(array_map(
            fn (array $w): array => [DB::connection()->getDatabaseName(), $w[0] === 'open' ? 'open' : 'close', (string) $this->raceStaff->id, (string) $this->raceClient->id, $w[1], $w[2], $w[3]],
            $workers,
        ), 30.0, 'kyc-unlock-race-worker.php');
        $this->raceOpen = fn (): bool => app(ReviewUnlocks::class)->until($this->raceStaff->id, $this->raceClient->id) !== null;
    });

    test('Hide documents wins over a Show request that is still holding the row', function () {
        ($this->raceSeed)('kyc_unlock_hide_wins');
        // The page showed the current generation. "open" takes the row and holds it 1.5 s; "close" arrives 0.5 s later.
        $results = ($this->raceRun)([
            'open' => ['open', $this->raceGeneration, '0', '1500'],
            'close' => ['close', '-', '500', '0'],
        ]);

        expect($results['open']['outcome'])->toBe('applied')
            ->and($results['close']['outcome'])->toBe('applied')
            ->and($results['close']['at'])->toBeGreaterThan($results['open']['at'] - 0.2)
            ->and(($this->raceOpen)())->toBeFalse();
    });

    test('a Show request that read the page before Hide documents cannot restore the unlock', function () {
        ($this->raceSeed)('kyc_unlock_stale_show');
        // "close" holds the row 1.5 s. "open" comes from the page that showed the generation before Hide, 0.5 s later, and waits.
        $results = ($this->raceRun)([
            'close' => ['close', '-', '0', '1500'],
            'open' => ['open', $this->raceGeneration, '500', '0'],
        ]);

        expect($results['close']['outcome'])->toBe('applied')
            ->and($results['open']['outcome'])->toBe('refused')
            ->and(($this->raceOpen)())->toBeFalse();
    });
});
