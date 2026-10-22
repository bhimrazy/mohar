<?php

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * Re-check probes for PR #308 at a877a1ec. An unlock now belongs to one session (session_hash). When another session
 * of the same login takes it over or ends it, the first session's browser still holds the numbers in its encrypted
 * history: spec §7 / #240 say that history is cleared on the first page after the unlock ends.
 */

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    $this->client = Client::factory()->create();
    $this->document = KycDocument::factory()->create(['client_id' => $this->client->id, 'number' => '27-01-74-04821']);
    Storage::disk(KycDocument::DISK)->put($this->document->front_path, Crypt::encryptString('front-bytes'));
    $this->staff = actingAsStaff(['kyc.documents.view']);
    $this->cookie = (string) config('session.cookie');
    $this->a = Str::random(40);
    $this->b = Str::random(40);
    $this->asBrowser = function (string $id): void {
        // flushSession(): the test session store keeps attributes in memory across ids, so without it one "browser"
        // would read the other's one-time flags. Each request then loads only what this id saved.
        $this->withCookie($this->cookie, $id)->flushSession();
        $this->actingAs($this->staff);
    };
    $this->showIn = function (string $id): void {
        ($this->asBrowser)($id);
        confirmPassword();
        $this->post(route('admin.kyc.unlock', $this->client), kycUnlockData($this->client))->assertRedirect();
    };
    $this->pageIn = function (string $id): array {
        ($this->asBrowser)($id);

        return $this->get(route('admin.kyc.index'))->assertOk()->viewData('page');
    };
});

// CTO decision on #308: each session has its own unlock, so a Show in a second browser does not end the first
// browser's (the probe first expected its history to be cleared, which would need Show to end it).
test('R1: Show in a second browser of the same login leaves the first browser\'s unlock and history alone', function () {
    ($this->showIn)($this->a);
    ($this->showIn)($this->b);

    ($this->asBrowser)($this->a);
    $this->get(route('admin.kyc.show', $this->client))->assertInertia(fn (AssertableInertia $page) => $page->where('documents.0.number', '27-01-74-04821'));
    expect(($this->pageIn)($this->a))->not->toHaveKey('clearHistory');
});

test('R1: Hide in a second browser clears the first browser\'s history on its next page', function () {
    ($this->showIn)($this->a);
    ($this->asBrowser)($this->b);
    $this->post(route('admin.kyc.lock', $this->client))->assertRedirect();

    expect(($this->pageIn)($this->a))->toHaveKey('clearHistory', true);
});

test('control: the same harness sees the clearing when the first browser\'s own unlock ends', function () {
    ($this->showIn)($this->a);
    $this->travel(16)->minutes();

    expect(($this->pageIn)($this->a))->toHaveKey('clearHistory', true);
});

test('R1: signing out in a second browser clears the first browser\'s history on its next page, and not twice', function () {
    ($this->showIn)($this->a);
    ($this->asBrowser)($this->b);
    $this->post(route('logout'))->assertRedirect();

    expect(($this->pageIn)($this->a))->toHaveKey('clearHistory', true)
        ->and(($this->pageIn)($this->a))->not->toHaveKey('clearHistory');
});
