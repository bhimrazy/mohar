<?php

use App\Actions\Fortify\PasswordGuesses;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
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
        ->assertJsonPath('message', 'Too many wrong passwords. Try again in 60 minutes. You have been signed out.');

    $this->assertGuest();
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
    });

    test('six wrong guesses at once with three left in the hourly budget: exactly three are compared', function () {
        $this->useSeededDatabase('password_guess_race', function (): void {
            $user = User::factory()->create(['email' => 'guess-race@example.test']);
            config(['cache.stores.database.connection' => DB::getDefaultConnection()]);
            app('cache')->forgetDriver('database');
            $limiter = new CacheRateLimiter(app('cache')->store('database'));

            foreach (range(1, PasswordGuesses::PER_HOUR - 3) as $earlier) {
                $limiter->increment('password-guess|'.$user->id, 3600);
            }
        });
        $user = User::query()->where('email', 'guess-race@example.test')->sole();
        $startAt = (string) (microtime(true) + 3);
        $workers = [];

        foreach (range(1, 6) as $worker) {
            $workers["w{$worker}"] = [DB::connection()->getDatabaseName(), (string) $user->id, '203.0.113.'.$worker, $startAt];
        }

        $outcomes = collect(passwordGuessRaceWorkers($workers))->countBy('outcome')->all();

        // The third compared guess is the twentieth wrong one: it ends its session.
        expect($outcomes)->toEqual(['wrong' => 2, 'ended' => 1, 'refused' => 3]);
    });
});
