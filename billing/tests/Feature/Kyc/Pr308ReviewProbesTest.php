<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\ReviewUnlocks;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Support/race.php';

/*
 * Review-board probes for PR #308 (kyc_review_unlocks). Each test states the behaviour the spec asks for; the ones
 * that fail at 62fdb5f7 are findings, the rest pin what the review verified.
 */

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    $this->client = Client::factory()->create();
    $this->document = KycDocument::factory()->create(['client_id' => $this->client->id, 'number' => '27-01-74-04821']);
    Storage::disk(KycDocument::DISK)->put($this->document->front_path, Crypt::encryptString('front-bytes'));
    $this->staff = actingAsStaff(['kyc.documents.view']);
    $this->page = fn () => $this->get(route('admin.kyc.show', $this->client))->assertOk();
    $this->generation = fn (): int => ($this->page)()->viewData('page')['props']['unlockGeneration'];
    $this->show = function (): void {
        confirmPassword();
        $this->post(route('admin.kyc.unlock', $this->client), ['generation' => ($this->generation)()])->assertRedirect();
    };
});

test('B1: a second session of the same staff member, with no password step-up, does not see the documents', function () {
    ($this->show)();
    ($this->page)()->assertInertia(fn (AssertableInertia $page) => $page->where('documents.0.number', '27-01-74-04821'));

    // Another browser or a copied session cookie: same login, its own session, never confirmed the password.
    $this->withCookie((string) config('session.cookie'), Str::random(40))->flushSession();
    $this->actingAs($this->staff);

    ($this->page)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('unlockedUntil', null)->where('documents.0.number', null)->where('documents.0.images', []));
});

test('B1: logging out ends the unlock; signing in again inside the 15 minutes does not show the documents', function () {
    ($this->show)();

    $this->post(route('logout'))->assertRedirect();
    $this->actingAs($this->staff);

    ($this->page)()->assertInertia(fn (AssertableInertia $page) => $page->where('unlockedUntil', null)->where('documents.0.number', null));
});

test('M1 (CTO decision): Show without a generation is refused as invalid, it is not a deliberate new Show', function () {
    confirmPassword();

    $this->post(route('admin.kyc.unlock', $this->client))->assertSessionHasErrors('generation');
    expect(app(ReviewUnlocks::class)->until($this->staff->id, $this->client->id))->toBeNull();
});

test('M3: an unlock open in the session at upgrade has the browser history cleared once it ends', function () {
    // What the previous release wrote (session key kyc.unlocks.<staff id>, client id => expiry timestamp).
    $this->withSession(['kyc.unlocks.'.$this->staff->id => [$this->client->id => now()->addMinutes(5)->getTimestamp()]]);
    ($this->page)();

    $this->travel(6)->minutes();
    $page = $this->get(route('admin.kyc.index'))->assertOk()->viewData('page');

    expect($page)->toHaveKey('clearHistory', true);
});

test('M3: Hide documents on the new release also drops the unlock the previous release keeps in the session', function () {
    $key = 'kyc.unlocks.'.$this->staff->id;
    $this->withSession([$key => [$this->client->id => now()->addMinutes(5)->getTimestamp()]]);

    $this->post(route('admin.kyc.lock', $this->client))->assertRedirect();

    // A request the old release still serves (blue-green overlap, or a rollback) reads this key and shows the documents.
    expect(session()->get($key, []))->toBe([]);
});

test('L: Show from a page whose unlock ended a moment ago (end not noticed yet) opens the documents', function () {
    ($this->show)();
    $generation = ($this->generation)();

    $this->travel(16)->minutes();
    // The step-up is stamped with the travelled clock too (time() does not follow travel()).
    $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    session()->save(); // the browser's session id is stable, so what the last request saved would win otherwise
    $this->post(route('admin.kyc.unlock', $this->client), ['generation' => $generation])->assertRedirect();

    ($this->page)()->assertInertia(fn (AssertableInertia $page) => $page->whereNot('unlockedUntil', null));
});

describe('the row lock in open() is what keeps Hide (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('M2: a Show that read the row before a Hide committed cannot write the unlock back', function () {
        $this->useSeededDatabase('kyc_unlock_gap', function (): void {
            $client = Client::factory()->create(['email' => 'kyc-gap@example.test']);
            $staff = User::factory()->staff(['*'])->create(['email' => 'kyc-gap-staff@example.test']);
            app(ReviewUnlocks::class)->open($staff->id, $client->id, now()->addMinutes(10), 0);
        });
        $client = Client::query()->where('email', 'kyc-gap@example.test')->sole();
        $staff = User::query()->where('email', 'kyc-gap-staff@example.test')->sole();
        $unlocks = app(ReviewUnlocks::class);
        $generation = (string) $unlocks->generation($staff->id, $client->id);
        $database = DB::connection()->getDatabaseName();

        // "hide" holds the row and commits Hide after 1.5 s; "show" (the page's current generation) starts 0.5 s in.
        $hide = proc_open([PHP_BINARY, __DIR__.'/Support/kyc-unlock-gap-worker.php', 'hide', $database, (string) $staff->id, (string) $client->id, '1500'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hidePipes);
        $results = kycRaceWorkers([
            'show' => [$database, 'open', (string) $staff->id, (string) $client->id, $generation, '500', '0'],
        ], 30.0, 'kyc-unlock-race-worker.php');
        $hideOut = stream_get_contents($hidePipes[1]).stream_get_contents($hidePipes[2]);
        proc_close($hide);

        expect(json_decode(trim($hideOut), true)['outcome'] ?? $hideOut)->toBe('applied')
            ->and($results['show']['outcome'])->toBe('refused')
            ->and($unlocks->until($staff->id, $client->id))->toBeNull();
    });
});
