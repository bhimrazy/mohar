<?php

use App\Actions\Fortify\PasswordGuesses;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/*
 * Re-review probes for #536 at 47fc1f5c.
 */

function rr536Request(string $ip): Request
{
    return Request::create('/user/confirm-password', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json']);
}

/*
 * R1. A guess counted in one hour window and refunded after the window ended decrements the new window's counter,
 * taking back a wrong guess another request counted there.
 */
test('R1: a refund across the end of the window does not take back a guess counted in the new window', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $hour = 'password-guess|'.$user->id;

    PasswordGuesses::check($user, rr536Request('203.0.113.1'), 'password', function () use ($user): bool {
        $this->travel(3601)->seconds(); // the compare runs across the end of the hour
        PasswordGuesses::check($user, rr536Request('203.0.113.2'), 'password', fn (): bool => false);

        return true;
    });

    expect(Cache::store(config('cache.limiter'))->get($hour))->toBe(1);
});

/*
 * R2. The same through the refusal path, which an attacker without the password can use: a refused guess counted at
 * the end of an exhausted hour and refunded once the next hour's counter exists lowers that counter.
 */
test('R2: a refused guess refunded across the end of the window does not lower the new window counter', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $hour = 'password-guess|'.$user->id;

    $racing = new class(new ArrayStore) extends Repository
    {
        public ?Closure $beforeHas = null;

        public function has($key): bool
        {
            if ($this->beforeHas !== null) {
                $hook = $this->beforeHas;
                $this->beforeHas = null;
                $hook();
            }

            return parent::has($key);
        }
    };
    Cache::extend('rr536-racing', fn () => $racing);
    config(['cache.stores.rr536-racing' => ['driver' => 'rr536-racing'], 'cache.limiter' => 'rr536-racing']);

    $racing->add($hour.':timer', now()->getTimestamp() + 3600, 3600);
    $racing->add($hour, PasswordGuesses::PER_HOUR, 3600); // budget used up

    $this->travel(3599)->seconds();
    $racing->beforeHas = function () use ($user): void {
        $this->travel(2)->seconds(); // another request opens the next hour with a wrong guess
        PasswordGuesses::check($user, rr536Request('203.0.113.9'), 'password', fn (): bool => false);
    };

    expect(fn () => PasswordGuesses::check($user, rr536Request('203.0.113.1'), 'password', fn (): bool => false))
        ->toThrow(HttpResponseException::class);

    expect($racing->get($hour))->toBe(1);
});
