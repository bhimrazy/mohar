<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

/**
 * Step-up on GET downloads (#272): a plain browser navigation cannot show the confirm dialog, so it is sent to the
 * confirm-password page and returned to the download afterwards. The file is never sent without a recent confirmation.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();
    $this->run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'not-an-email')])));
    $this->url = route('admin.migration.imports.errors', $this->run);
    $this->flushSession();
});

test('a plain download without a recent password goes to the confirm page and returns to the file after confirming', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);

    $this->get($this->url)->assertRedirect(route('password.confirm'))
        ->assertInertiaFlash('toast.message', 'Confirm your password to download this file.');
    expect(session('url.intended'))->toBe($this->url);

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect($this->url);

    $this->get($this->url)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('a stale confirmation counts as none, and a wrong password does not unlock the file', function () {
    $this->withSession(['auth.password_confirmed_at' => time() - config('auth.step_up_seconds') - 1]);

    $this->get($this->url)->assertRedirect(route('password.confirm'));
    $this->post(route('password.confirm.store'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->get($this->url)->assertRedirect(route('password.confirm'));
});

test('a probe says whether the file may be fetched and never reaches the controller', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->get($this->url, ['X-Step-Up-Check' => '1'])->assertStatus(423);

    confirmPassword();
    $this->get($this->url, ['X-Step-Up-Check' => '1'])->assertNoContent();
    expect(ActivityLog::query()->where('event', 'migration.errors_downloaded')->count())->toBe(0);

    $this->get($this->url)->assertOk();
    expect(ActivityLog::query()->where('event', 'migration.errors_downloaded')->count())->toBe(1);
});

test('an Inertia or JSON request never receives the file in place', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->getJson($this->url)->assertStatus(423);
    $this->get($this->url, ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', $this->url);

    confirmPassword();
    $this->get($this->url, ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', $this->url);
});

test('a mutation without a recent password is still refused with the validation error the dialog reads', function () {
    $this->withSession(['auth.password_confirmed_at' => 0]);

    $this->post(route('admin.migration.imports.accept', $this->run), [], ['X-Inertia' => 'true'])->assertSessionHasErrors('password_confirmation');
});

test('every GET route behind step-up is a download this fix covers', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true) && in_array('password.recent', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->getName())->sort()->values()->all();

    expect($routes)->toBe(['admin.migration.imports.errors']);
});
