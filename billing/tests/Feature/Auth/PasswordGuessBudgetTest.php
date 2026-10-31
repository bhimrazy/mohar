<?php

use App\Actions\Fortify\PasswordGuesses;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/Support/race.php';

/*
 * #523: a guess is counted before the password is compared and refunded unless it was wrong, so concurrent guesses
 * cannot pass the hourly cap; the wrong password that uses the cap up ends the session; a client login's failed
 * step-up is logged on the client account it acts for.
 */

function guessRequest(string $ip): Request
{
    return Request::create('/user/confirm-password', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json']);
}

test('a guess still being compared already counts, so a concurrent one past the cap is refused without a compare', function () {
    $user = User::factory()->create();
    $hour = 'password-guess|'.$user->id;

    foreach (range(1, PasswordGuesses::PER_HOUR - 1) as $earlier) {
        RateLimiter::increment($hour, 3600);
    }

    $innerCompared = false;
    $refusal = null;

    $outer = PasswordGuesses::check($user, guessRequest('203.0.113.1'), 'password', function () use ($user, &$innerCompared, &$refusal): bool {
        try {
            PasswordGuesses::check($user, guessRequest('203.0.113.2'), 'password', function () use (&$innerCompared): bool {
                $innerCompared = true;

                return true;
            });
        } catch (HttpResponseException $e) {
            $refusal = $e->getResponse();
        }

        return true;
    });

    expect($outer)->toBeTrue()
        ->and($innerCompared)->toBeFalse()
        ->and($refusal?->getStatusCode())->toBe(429)
        // Both refunded: the refused one never compared, the outer one was right.
        ->and(RateLimiter::attempts($hour))->toBe(PasswordGuesses::PER_HOUR - 1);
});

test('the wrong password that uses up the hourly budget ends the session and is logged', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    foreach (range(1, PasswordGuesses::PER_HOUR - 1) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.intdiv($attempt, PasswordGuesses::PER_MINUTE)])
            ->postJson(route('password.confirm.store'), ['password' => 'wrong-'.$attempt])->assertUnprocessable();
    }

    $this->assertAuthenticatedAs($user);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson(route('password.confirm.store'), ['password' => 'wrong-last'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', 'Too many wrong passwords. Try again in 60 minutes. You have been signed out.')
        ->assertJsonPath('signed_out', true)
        ->assertJsonPath('redirect', route('login'));

    $this->assertGuest();
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('status', 'Too many wrong passwords. Try again in 60 minutes. You have been signed out.'));
    expect(ActivityLog::query()->where('event', 'auth.password_guesses_exhausted')->sole()->user_id)->toBe($user->id)
        ->and(ActivityLog::query()->where('event', 'auth.confirm_password_failed')->count())->toBe(PasswordGuesses::PER_HOUR);
});

test('a full-page form that uses up the budget lands on sign-in with the reason', function () {
    $this->freezeTime();
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);

    foreach (range(1, PasswordGuesses::PER_HOUR - 1) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.intdiv($attempt, PasswordGuesses::PER_MINUTE)])
            ->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'wrong-'.$attempt])->assertSessionHasErrors('password');
    }

    $this->travel(59)->minutes();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'wrong-last'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Too many wrong passwords. Try again in 1 minute. You have been signed out.');

    $this->assertGuest();
    expect($user->fresh())->not->toBeNull();
});

test('a failed step-up by a client sub-login is on the account it acts for; a staff one is on none', function () {
    [, $owned] = actingAsClient();
    $acting = Client::factory()->create();
    $subLogin = User::factory()->create(['password' => 'correct-horse-battery']);
    $owned->users()->attach($subLogin, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);
    $acting->users()->attach($subLogin, ['is_owner' => false, 'permissions' => json_encode(['billing'])]);

    $this->actingAs($subLogin)->withSession([ClientContext::SESSION_KEY => $acting->id])
        ->postJson(route('password.confirm.store'), ['password' => 'wrong-guess'])->assertUnprocessable();

    $staff = actingAsStaff();
    $this->postJson(route('password.confirm.store'), ['password' => 'wrong-guess'])->assertUnprocessable();

    $rows = ActivityLog::query()->where('event', 'auth.confirm_password_failed')->get()->keyBy('user_id');
    expect($rows[$subLogin->id]->client_id)->toBe($acting->id)
        ->and($rows[$staff->id]->client_id)->toBeNull();
});

describe('concurrent guesses in separate processes (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate processes sharing one database cache store (PostgreSQL).');
        }

        // One committed database per test with a login whose hourly counter is set up by $earlier.
        $this->raceSeed = function (string $name, Closure $earlier): User {
            $this->useSeededDatabase($name, function () use ($name, $earlier): void {
                $user = User::factory()->create(['email' => "{$name}@example.test"]);
                config(['cache.stores.database.connection' => DB::getDefaultConnection()]);
                app('cache')->forgetDriver('database');
                $earlier(app('cache')->store('database'), 'password-guess|'.$user->id);
            });

            return User::query()->where('email', "{$name}@example.test")->sole();
        };
        $this->raceRun = function (User $user, int $count): array {
            $startAt = (string) (microtime(true) + 3);
            $workers = [];

            foreach (range(1, $count) as $worker) {
                $workers["w{$worker}"] = ['wrong', DB::connection()->getDatabaseName(), (string) $user->id, '203.0.113.'.$worker, $startAt];
            }

            $outcomes = collect(passwordGuessRaceWorkers($workers))->countBy('outcome')->all();
            app('cache')->forgetDriver('database');

            return [$outcomes, app('cache')->store('database')->get('password-guess|'.$user->id)];
        };
    });

    test('six wrong guesses at once with three left in the hourly budget: exactly three are compared', function () {
        $user = ($this->raceSeed)('password_guess_race', function (Repository $store, string $hour): void {
            $store->add($hour.':timer', now()->getTimestamp() + 3600, 3600);
            $store->add($hour, PasswordGuesses::PER_HOUR - 3, 3600);
        });

        [$outcomes, $count] = ($this->raceRun)($user, 6);

        // The third compared guess is the twentieth wrong one: it ends its session.
        expect($outcomes)->toEqual(['wrong' => 2, 'ended' => 1, 'refused' => 3])
            ->and($count)->toBe(PasswordGuesses::PER_HOUR);
    });

    test('#536 B1: from a counter a refund brought back to 0, every concurrent wrong guess is counted', function () {
        $user = ($this->raceSeed)('password_guess_zero', function (Repository $store, string $hour): void {
            $store->add($hour.':timer', now()->getTimestamp() + 3600, 3600);
            $store->add($hour, 0, 3600);
            $store->increment($hour);
            $store->decrement($hour);
        });

        [$outcomes, $count] = ($this->raceRun)($user, 8);

        expect($outcomes)->toEqual(['wrong' => 8])->and($count)->toBe(8);
    });
});
