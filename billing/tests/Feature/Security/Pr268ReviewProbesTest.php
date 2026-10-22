<?php

use Illuminate\Support\Facades\Route;

/**
 * Review-board probes for PR #268 at 23db7ba5: the kernel `password.recent` reads auth.step_up_seconds, ignores the
 * removed platform key, and cannot be passed by a request without a session (token clients) or a stale timestamp.
 */
beforeEach(function () {
    Route::middleware(['web', 'password.recent'])->post('_probe/pr268/step-up', fn () => response('ok'));
});

test('the window comes from auth.step_up_seconds, and the removed platform key no longer widens it', function () {
    config(['auth.step_up_seconds' => 60, 'platform.password_confirmation_seconds' => 100_000]);

    $this->withSession(['auth.password_confirmed_at' => time() - 61])->postJson('_probe/pr268/step-up')->assertStatus(423);
    $this->withSession(['auth.password_confirmed_at' => time() - 30])->postJson('_probe/pr268/step-up')->assertOk();
});

test('the window is exclusive at its edge: a confirmation exactly step_up_seconds old is refused', function () {
    $this->withSession(['auth.password_confirmed_at' => time() - 900])->postJson('_probe/pr268/step-up')->assertStatus(423);
    $this->withSession(['auth.password_confirmed_at' => time() - 899])->postJson('_probe/pr268/step-up')->assertOk();
});

test('missing or non-numeric timestamps fail closed', function (mixed $stamp) {
    $this->withSession(['auth.password_confirmed_at' => $stamp])->postJson('_probe/pr268/step-up')->assertStatus(423);
})->with([[null], [0], ['yes'], [''], [[]]]);

test('a request without a session (API token style) gets 423, not a pass', function () {
    Route::middleware('password.recent')->post('_probe/pr268/stateless', fn () => response('ok'));

    $this->postJson('_probe/pr268/stateless')->assertStatus(423);
});

test('an Inertia request gets the password_confirmation error the gate opens the dialog on', function () {
    $this->withHeaders(['X-Inertia' => 'true'])->withSession(['auth.password_confirmed_at' => 0])
        ->post('_probe/pr268/step-up')->assertSessionHasErrors('password_confirmation');
});
