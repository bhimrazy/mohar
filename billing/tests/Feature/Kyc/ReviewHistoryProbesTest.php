<?php

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/*
 * Review-board probes for PR #249 (issue #240). Each test states the behaviour the fix should have; a failure is the
 * finding.
 */

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    $this->client = Client::factory()->create();
    $this->document = KycDocument::factory()->create(['client_id' => $this->client->id, 'number' => '27-01-74-04821']);
    Storage::disk(KycDocument::DISK)->put($this->document->front_path, Crypt::encryptString('front-bytes'));
    $this->staff = actingAsStaff(['kyc.documents.view']);
    confirmPassword();
    $this->post(route('admin.kyc.unlock', $this->client), kycUnlockData($this->client))->assertRedirect();
});

// Finding M1: the PR body says "logout, forced logout", but a session ended server side (staff deactivated, which
// deletes the session rows in UpdateStaff; or SESSION_LIFETIME idle expiry) never gets the flag: the next request
// has no signed-in holder and no unlock entry, so nothing clears the tab's history while it can still be decrypted.
test('probe: a session ended server side while documents were open still clears the history on the next page', function () {
    // The session row is deleted (UpdateStaff::endSessions) and the guard has no user on the next request.
    $this->flushSession();
    app('auth')->forgetGuards();

    $page = $this->get(route('login'))->assertOk()->viewData('page');

    expect($page)->toHaveKey('clearHistory', true);
});

// Finding L1: the prefetch skip only protects forgetEnded(). A flag already waiting in the session (set by a
// request that rendered no Inertia page, e.g. the expired image link) is pulled by the next hover prefetch.
test('probe: a flag already in the session is not used up by a hover prefetch', function () {
    $image = $this->get(route('admin.kyc.show', $this->client))->viewData('page')['props']['documents'][0]['images'][0]['url'];
    $this->travel(16)->minutes();

    // The image request is the first request after the end: the middleware forgets the unlock and sets the flag,
    // and the response (403) is not an Inertia page, so the flag stays in the session.
    $this->get($image)->assertForbidden();

    $prefetch = $this->get(route('admin.kyc.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'Purpose' => 'prefetch'])->assertOk()->json();

    expect($prefetch)->not->toHaveKey('clearHistory')
        ->and($this->get(route('admin.kyc.index'))->viewData('page'))->toHaveKey('clearHistory', true);
});

// Control: deactivation caught by EnsureActiveUser in the same request.
test('control: a deactivated staff member signed out by EnsureActiveUser gets the flag on the next page', function () {
    $this->staff->forceFill(['is_active' => false])->save();
    $this->get(route('admin.kyc.index'))->assertStatus(401);
    app('auth')->forgetGuards();

    expect($this->get(route('login'))->assertOk()->viewData('page'))->toHaveKey('clearHistory', true);
});
