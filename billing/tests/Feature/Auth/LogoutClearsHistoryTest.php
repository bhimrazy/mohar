<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Inertia\Inertia;

/*
 * Issue #250: every way a user leaves a signed-in browser tab clears the encrypted Inertia history, not only the
 * KYC document unlock. The flag is a one-time session value, so each test reads it from the next page shown.
 */

beforeEach(function () {
    $this->page = fn (string $url): array => $this->get($url)->assertOk()->viewData('page');
});

test('a staff logout clears the history on the page it leads to', function () {
    actingAsStaff();

    $this->post(route('logout'))->assertRedirect();

    expect(($this->page)(route('home')))->toHaveKey('clearHistory', true)
        ->and(($this->page)(route('home')))->not->toHaveKey('clearHistory');
});

test('a client logout clears the history on the page it leads to', function () {
    actingAsClient();

    $this->post(route('logout'))->assertRedirect();

    expect(($this->page)(route('home')))->toHaveKey('clearHistory', true);
});

test('deleting your own account clears the history', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect();

    expect(($this->page)(route('home')))->toHaveKey('clearHistory', true);
});

test('a login signed out because it was deactivated clears the history on the next page', function () {
    $staff = actingAsStaff();
    $staff->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertUnauthorized();

    expect(($this->page)(route('home')))->toHaveKey('clearHistory', true);
});

test('a session that ended on the server clears the history on the login page it leads to', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    expect(($this->page)(route('login')))->toHaveKey('clearHistory', true)
        ->and(($this->page)(route('home')))->not->toHaveKey('clearHistory');
});

test('a plain sign in does not clear the history', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    expect(($this->page)(route('profile.edit')))->not->toHaveKey('clearHistory');
});

test('signing in as a client and returning to the admin area each clear the history', function () {
    Currency::factory()->create();
    $client = Client::factory()->withOwner()->create();
    actingAsStaff(['core.clients.view', 'core.clients.impersonate']);
    confirmPassword();

    $url = (string) $this->post(route('admin.platform.clients.impersonate', $client), [], ['X-Inertia' => 'true'])->headers->get('X-Inertia-Location');
    $this->get($url)->assertRedirect(route('client.dashboard'));
    expect(($this->page)(route('client.dashboard')))->toHaveKey('clearHistory', true);

    $this->post(route('platform.impersonation.leave'))->assertRedirect(route('admin.clients.show', $client));
    expect(($this->page)(route('admin.clients.show', $client)))->toHaveKey('clearHistory', true);
});

test('a hover prefetch of the page after logout does not use up the clearing', function () {
    actingAsStaff();
    $this->post(route('logout'))->assertRedirect();

    $prefetch = $this->get(route('home'), ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'Purpose' => 'prefetch'])->assertOk()->json();

    expect($prefetch)->not->toHaveKey('clearHistory')
        ->and(($this->page)(route('home')))->toHaveKey('clearHistory', true);
});
