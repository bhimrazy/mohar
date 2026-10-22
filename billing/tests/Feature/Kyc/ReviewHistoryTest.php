<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/*
 * Issue #240: the review page's history is encrypted, and cleared (Inertia clearHistory) once the document unlock
 * ends, so Back or a restored session cannot bring the numbers back.
 */

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    $this->client = Client::factory()->create();
    $this->document = KycDocument::factory()->create(['client_id' => $this->client->id, 'number' => '27-01-74-04821']);
    Storage::disk(KycDocument::DISK)->put($this->document->front_path, Crypt::encryptString('front-bytes'));
    $this->staff = actingAsStaff(['kyc.documents.view']);
    $this->unlock = function (?Client $client = null): void {
        confirmPassword();
        $this->post(route('admin.kyc.unlock', $client ?? $this->client), kycUnlockData($client ?? $this->client))->assertRedirect();
    };
    $this->page = fn (?string $url = null): array => $this->get($url ?? route('admin.kyc.show', $this->client))->assertOk()->viewData('page');
});

test('the page is encrypted, and history is not cleared while the unlock is open or for a client never unlocked', function () {
    $other = Client::factory()->create();

    expect(($this->page)())->toHaveKey('encryptHistory', true)->not->toHaveKey('clearHistory')
        ->and(($this->page)(route('admin.kyc.show', $other)))->not->toHaveKey('clearHistory');

    ($this->unlock)();
    $page = ($this->page)();
    expect($page)->toHaveKey('encryptHistory', true)->not->toHaveKey('clearHistory')
        ->and($page['props']['documents'][0]['number'])->toBe('27-01-74-04821');

    $this->travel(14)->minutes();
    expect(($this->page)())->not->toHaveKey('clearHistory')
        ->and(($this->page)(route('admin.kyc.show', $other)))->not->toHaveKey('clearHistory');
});

test('the first page after the unlock ends clears the history, once, and shows no number', function () {
    ($this->unlock)();
    $this->travel(16)->minutes();

    $page = ($this->page)();
    expect($page)->toHaveKey('clearHistory', true)
        ->and($page['props']['unlockedUntil'])->toBeNull()
        ->and($page['props']['documents'][0]['number'])->toBeNull()
        ->and(($this->page)())->not->toHaveKey('clearHistory');
});

test('an unlock that ended while the staff member was on another page clears the history there', function () {
    ($this->unlock)();
    $this->travel(16)->minutes();

    expect(($this->page)(route('admin.kyc.index')))->toHaveKey('clearHistory', true)
        ->and(($this->page)())->not->toHaveKey('clearHistory');
});

test('a hover prefetch does not use up the history clearing; the next visit gets it', function () {
    ($this->unlock)();
    $this->travel(16)->minutes();

    $prefetch = $this->get(route('admin.kyc.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'Purpose' => 'prefetch'])->assertOk()->json();

    expect($prefetch)->not->toHaveKey('clearHistory')
        ->and(($this->page)(route('admin.kyc.index')))->toHaveKey('clearHistory', true);
});

test('an unlock cut short by the end of the password step-up clears the history when it ends', function () {
    $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(10)->getTimestamp()]);
    $this->post(route('admin.kyc.unlock', $this->client), kycUnlockData($this->client))->assertRedirect();

    $this->travel(4)->minutes();
    expect(($this->page)())->not->toHaveKey('clearHistory');
    $this->travel(2)->minutes();
    expect(($this->page)())->toHaveKey('clearHistory', true);
});

test('unlocking another client after one ended still clears the history of the ended one', function () {
    ($this->unlock)();
    $this->travel(16)->minutes();
    $other = Client::factory()->create();

    // confirmPassword() uses time(), which travel() does not move.
    $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    session()->save(); // the browser's session id is stable, so what the last request saved would win otherwise
    $this->post(route('admin.kyc.unlock', $other), kycUnlockData($other))->assertRedirect();
    $page = ($this->page)(route('admin.kyc.show', $other));

    expect($page)->toHaveKey('clearHistory', true)->and($page['props']['unlockedUntil'])->not->toBeNull();
});

test('Hide documents locks the client again, clears the history and is recorded', function () {
    ($this->unlock)();
    $image = ($this->page)()['props']['documents'][0]['images'][0]['url'];

    $this->post(route('admin.kyc.lock', $this->client))->assertRedirect(route('admin.kyc.show', $this->client));
    $page = ($this->page)();

    expect($page)->toHaveKey('clearHistory', true)
        ->and($page['props']['unlockedUntil'])->toBeNull()
        ->and($page['props']['documents'][0]['number'])->toBeNull()
        ->and(ActivityLog::query()->where('event', 'kyc.documents.closed')->sole()->client_id)->toBe($this->client->id);
    $this->get($image)->assertForbidden();

    // Hiding a client that is not open changes nothing and records nothing.
    $this->post(route('admin.kyc.lock', $this->client))->assertRedirect();
    expect(($this->page)())->not->toHaveKey('clearHistory')
        ->and(ActivityLog::query()->where('event', 'kyc.documents.closed')->count())->toBe(1);
});

test('Hide documents needs a KYC permission and leaves other clients open', function () {
    $other = Client::factory()->create();
    KycDocument::factory()->create(['client_id' => $other->id, 'number' => '99-88-77-66666']);
    ($this->unlock)();
    ($this->unlock)($other);

    $this->post(route('admin.kyc.lock', $this->client))->assertRedirect();
    expect(($this->page)(route('admin.kyc.show', $other))['props']['documents'][0]['number'])->toBe('99-88-77-66666');

    actingAsStaff(['clients.view']);
    $this->post(route('admin.kyc.lock', $other))->assertForbidden();
});

test('logging out while documents are open clears the history on the page logout leads to', function () {
    ($this->unlock)();

    $this->post(route('logout'))->assertRedirect();

    expect(($this->page)(route('home')))->toHaveKey('clearHistory', true);
});

test('logging out without having opened documents does not clear the history there', function () {
    $this->post(route('logout'))->assertRedirect();

    expect(($this->page)(route('home')))->not->toHaveKey('clearHistory');
});

test('the login and two-factor challenge pages always clear the history for a guest, other guest pages do not', function () {
    auth()->logout();

    expect(($this->page)(route('login')))->toHaveKey('clearHistory', true)
        ->and(($this->page)(route('home')))->not->toHaveKey('clearHistory');

    $this->withSession(['login.id' => $this->staff->id, 'login.remember' => false]);
    $this->get(route('two-factor.login'))->assertOk()->assertInertia(fn ($page) => expect($page->toArray())->toHaveKey('clearHistory', true));
});
