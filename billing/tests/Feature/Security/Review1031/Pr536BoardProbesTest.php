<?php

use App\Actions\Fortify\PasswordGuesses;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Currency;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fakes\Catalog\FakeProvisioningDrivers;

/*
 * Review board probes for #536. Each test states the behaviour the review asks for; a red one reproduces a finding.
 */

function pr536Request(string $ip): Request
{
    return Request::create('/user/confirm-password', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json']);
}

/*
 * P1. Illuminate\Cache\RateLimiter::increment() ends with `if (! $added && $hits == $amount) put($key, $amount)`.
 * Once a refund has brought an existing hour counter back to 0 (any correct step-up does), the next guess takes that
 * branch and overwrites the counter with 1: a guess counted by another request between that increment and the put
 * is lost. The repository below injects that other request's increment at exactly that point.
 * Adopted (fix round): PasswordGuesses now counts on the limiter's store directly, so the racing repository is
 * installed as that store (cache.limiter) instead of being wrapped in a RateLimiter.
 */
test('P1: a guess counted concurrently with the 0 -> 1 transition is not lost', function () {
    $user = User::factory()->create();
    $hour = 'password-guess|'.$user->id;

    $racing = new class(new ArrayStore) extends Repository
    {
        public ?string $raceKey = null;

        public function increment($key, $value = 1)
        {
            $result = parent::increment($key, $value);

            if ($key === $this->raceKey && $value === 1 && $result === 1) {
                $this->raceKey = null;
                parent::increment($key, 1); // the concurrent wrong guess of another request lands here
            }

            return $result;
        }
    };
    Cache::extend('pr536-racing', fn () => $racing);
    config(['cache.stores.pr536-racing' => ['driver' => 'pr536-racing'], 'cache.limiter' => 'pr536-racing']);

    // A correct step-up earlier in the hour: counted, then refunded, so the key exists at 0.
    PasswordGuesses::check($user, pr536Request('203.0.113.1'), 'password', fn (): bool => true);
    expect($racing->get($hour))->toBe(0);

    $racing->raceKey = $hour;
    PasswordGuesses::check($user, pr536Request('203.0.113.2'), 'password', fn (): bool => false);

    expect($racing->get($hour))->toBe(2);
});

/*
 * P2. The in-process interleaving test, run against #522's check-then-count order, emulated here: the nested guess is
 * compared, so the PR's test does discriminate when the old order is reproduced faithfully.
 */
test('P2: #522 order lets the nested guess compare (the PR interleaving test would be red on it)', function () {
    $user = User::factory()->create();
    $hour = 'password-guess|'.$user->id;
    foreach (range(1, PasswordGuesses::PER_HOUR - 1) as $i) {
        RateLimiter::increment($hour, 3600);
    }

    $old = function (Closure $compare) use ($hour): bool {
        if (RateLimiter::tooManyAttempts($hour, PasswordGuesses::PER_HOUR)) {
            throw new HttpResponseException(response('', 429));
        }
        $ok = $compare();
        if (! $ok) {
            RateLimiter::hit($hour, 3600);
        }

        return $ok;
    };

    $innerCompared = false;
    $old(function () use ($old, &$innerCompared): bool {
        $old(function () use (&$innerCompared): bool {
            $innerCompared = true;

            return false;
        });

        return false;
    });

    expect($innerCompared)->toBeTrue()->and(RateLimiter::attempts($hour))->toBe(PasswordGuesses::PER_HOUR + 1);
});

/*
 * P3 (author's open question). With a secret kept, a non-secret setting changes without step-up, so a driver that reads
 * an endpoint from product settings would send the stored secret to the new one. Red = the gap exists.
 */
test('P3: changing a non-secret setting while the stored secret is kept needs step-up', function () {
    Currency::factory()->create();
    actingAsStaff(['catalog.products.manage']);
    FakeProvisioningDrivers::withLogDriver();
    $product = Product::factory()->create(['provisioning_driver' => 'log', 'driver_config' => ['plan' => 'starter', 'endpoint' => 'https://panel.example.test', 'api_token' => 'tok-12345']]);

    $this->from(route('admin.catalog.products.edit', $product))->put(route('admin.catalog.products.provisioning.update', $product), [
        'provisioning_driver' => 'log', 'driver_config' => [['key' => 'plan', 'value' => 'starter'], ['key' => 'endpoint', 'value' => 'https://attacker.example.test'], ['key' => 'api_token', 'value' => '']],
    ])->assertSessionHasErrors('password_confirmation');

    expect($product->fresh()->driver_config['endpoint'])->toBe('https://panel.example.test');
});

/*
 * P4. The secret check runs in prepareForValidation(), before authorize(): a login without the permission is asked
 * for its password instead of getting 403.
 */
test('P4: a login without catalog.products.manage gets 403, not a step-up prompt', function () {
    Currency::factory()->create();
    actingAsStaff(['catalog.products.view']);
    FakeProvisioningDrivers::withLogDriver();
    $product = Product::factory()->create(['provisioning_driver' => 'log', 'driver_config' => ['plan' => 'starter', 'api_token' => 'tok-12345']]);

    $this->putJson(route('admin.catalog.products.provisioning.update', $product), [
        'provisioning_driver' => 'log', 'driver_config' => [['key' => 'plan', 'value' => 'pro'], ['key' => 'api_token', 'value' => 'tok-new']],
    ])->assertForbidden();
});

/*
 * P5. password.recent tells a login without a password to set one (step_up_reason = password_not_set); the new
 * secret check says only "Confirm your password", which such a login can never do.
 */
test('P5: a passwordless staff login entering a secret is told to set a password', function () {
    Currency::factory()->create();
    $user = User::factory()->staff(['catalog.products.manage'])->create();
    DB::table('users')->where('id', $user->id)->update(['password' => null]);
    $this->actingAs($user->refresh());
    FakeProvisioningDrivers::withLogDriver();
    $product = Product::factory()->create(['provisioning_driver' => 'log', 'driver_config' => ['plan' => 'starter', 'api_token' => 'tok-12345']]);

    $this->from(route('admin.catalog.products.edit', $product))->put(route('admin.catalog.products.provisioning.update', $product), [
        'provisioning_driver' => 'log', 'driver_config' => [['key' => 'plan', 'value' => 'pro'], ['key' => 'api_token', 'value' => 'tok-new']],
    ], ['X-Inertia' => 'true'])->assertStatus(423)->assertHeader(RequireRecentPasswordConfirmation::REFUSAL_HEADER, '1')
        ->assertJsonPath('code', RequireRecentPasswordConfirmation::NO_PASSWORD_CODE);
});

/*
 * P6. The JSON 429 that ends the session should be distinguishable from an ordinary 429 refusal, so the step-up
 * dialog can send the user to sign-in instead of staying open on a dead session.
 */
test('P6: the session-ending 429 carries a machine-readable signal', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $this->actingAs($user);
    foreach (range(1, PasswordGuesses::PER_HOUR - 1) as $i) {
        RateLimiter::increment('password-guess|'.$user->id, 3600);
    }

    $response = $this->postJson(route('password.confirm.store'), ['password' => 'wrong']);

    $response->assertTooManyRequests();
    expect(array_diff(array_keys($response->json()), ['message', 'errors']))->not->toBe([]);
});
