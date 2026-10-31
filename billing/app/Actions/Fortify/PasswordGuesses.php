<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One failure budget per login for every check of its current password while signed in: step-up confirmation,
 * password change and account deletion (security review 2026-10-29, M1; #522). Only wrong passwords count, so
 * someone who knows the password never runs out, however short the step-up window. The hourly budget is keyed on
 * the login alone, so changing IP does not reset it; the wrong password that uses it up also ends the session
 * (#523). Running out blocks these checks only, not sign-in or "Forgot password".
 */
final class PasswordGuesses
{
    /** Wrong passwords per login and IP per minute (the sign-in form allows the same). */
    public const PER_MINUTE = 5;

    /** Wrong passwords per login per hour, from any IP. */
    public const PER_HOUR = 20;

    /**
     * Runs $compare within the budget and returns its result. A refused check never compares: 429 for JSON,
     * otherwise a validation error on $field.
     *
     * The guess is counted before the compare and refunded unless it was wrong, so concurrent guesses cannot all
     * pass a budget check made before any of them is counted (#523). Guesses still being compared count towards the
     * hourly cap too, so under concurrency a session can end before twenty of them have turned out wrong (fails
     * closed).
     *
     * Not to be called inside a database transaction: on the database cache store a refusal would roll its count back.
     *
     * @param  Closure(): bool  $compare
     * @param  (Closure(): void)|null  $onWrong  runs for a wrong password, before the session can end
     *
     * @throws HttpResponseException
     * @throws ValidationException
     */
    public static function check(User $user, Request $request, string $field, Closure $compare, ?Closure $onWrong = null): bool
    {
        $hour = self::hourKey($user);
        $minute = self::minuteKey($user, $request);
        [$hourCount, $hourWindow] = self::count($hour, 3600);
        [$minuteCount, $minuteWindow] = self::count($minute, 60);
        $counted = [$hour => $hourWindow, $minute => $minuteWindow];

        if ($hourCount > self::PER_HOUR || $minuteCount > self::PER_MINUTE) {
            self::refund($counted);
            [$key, $message] = $hourCount > self::PER_HOUR ? [$hour, self::hourMessage($hour)] : [$minute, self::minuteMessage($minute)];

            self::refuse($request, $field, $message, self::secondsLeft($key));
        }

        if ($compare()) {
            self::refund($counted);

            return true;
        }

        if ($onWrong !== null) {
            $onWrong();
        }

        if ($hourCount >= self::PER_HOUR) {
            self::endSession($user, $request, $field, $hour);
        }

        return false;
    }

    /**
     * The client account a client login is acting for, for its activity rows; null for staff.
     */
    public static function activityClientId(User $user, Request $request): ?int
    {
        if ($user->is_staff) {
            return null;
        }

        return app(ClientContext::class)->select($user, $request->hasSession() ? $request->session()->get(ClientContext::SESSION_KEY) : null)?->id;
    }

    private static function hourKey(User $user): string
    {
        return 'password-guess|'.$user->getKey();
    }

    private static function minuteKey(User $user, Request $request): string
    {
        return 'password-guess|'.$user->getKey().'|'.$request->ip();
    }

    /**
     * Counts one guess with the store's atomic add and increment only. RateLimiter::increment() is not used: it
     * overwrites the counter with put() on a 0 -> 1 step, which loses a concurrent guess once a refund has brought
     * the counter back to 0 (#536 review, B1). The window is the separate timer key (the RateLimiter's layout); the
     * counter expires with it.
     *
     * @return array{0: int, 1: int} the count, and the end of the window it was counted in (the timer's value)
     */
    private static function count(string $key, int $window): array
    {
        $store = self::store();
        $now = now()->getTimestamp();

        // increment() answers false when the counter row is gone (deleted between add and increment): count again.
        for ($try = 0; $try < 2; $try++) {
            $store->add($key.':timer', $now + $window, $window);
            $store->add($key, 0, max(1, self::secondsLeft($key)));
            $count = $store->increment($key);

            if ($count !== false) {
                return [(int) $count, (int) $store->get($key.':timer', 0)];
            }
        }

        // Never compare a guess that is not counted.
        throw new RuntimeException('The cache store did not count the password guess.');
    }

    /**
     * Takes a guess back from the window it was counted in only: once that window has ended, the counter may be the
     * next window's, and a refund would lower it (#536 re-review).
     *
     * @param  array<string, int>  $counted  key => the end of the window the guess was counted in
     */
    private static function refund(array $counted): void
    {
        $store = self::store();

        foreach ($counted as $key => $window) {
            if (! $store->has($key) || (int) $store->get($key.':timer', 0) !== $window) {
                continue;
            }

            // A store that creates a missing key on decrement (Redis) does so without an expiry, if the counter
            // expired just now: give it one, so it cannot outlive the window.
            if ((int) $store->decrement($key) < 0) {
                $store->touch($key, 1);
            }
        }
    }

    private static function secondsLeft(string $key): int
    {
        return max(0, (int) self::store()->get($key.':timer', 0) - now()->getTimestamp());
    }

    /** The rate limiter's store (cache.limiter, else the default). */
    private static function store(): Repository
    {
        return Cache::store(config('cache.limiter'));
    }

    private static function hourMessage(string $hour): string
    {
        $minutes = max(1, (int) ceil(self::secondsLeft($hour) / 60));

        return trans_choice('{1} Too many wrong passwords. Try again in :minutes minute.|[2,*] Too many wrong passwords. Try again in :minutes minutes.', $minutes, ['minutes' => $minutes]);
    }

    private static function minuteMessage(string $minute): string
    {
        return __('Too many password attempts. Try again in :seconds seconds.', ['seconds' => self::secondsLeft($minute)]);
    }

    /**
     * The session may be someone else's (M1's threat model), so it does not keep guessing from a fresh one either.
     * Only this session ends; but logout cycles the login's one remember-me token, so remember-me stops working on
     * every device.
     *
     * @throws HttpResponseException
     */
    private static function endSession(User $user, Request $request, string $field, string $hour): never
    {
        app(ActivityLogger::class)->log('Signed out after too many wrong passwords', $user, self::activityClientId($user, $request), event: 'auth.password_guesses_exhausted', actor: $user);

        Auth::guard('web')->logout();

        $message = self::hourMessage($hour).' '.__('You have been signed out.');

        // The sign-in page shows the reason, also when the step-up dialog sends the browser there.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('status', $message);
        }

        throw new HttpResponseException($request->wantsJson()
            // signed_out tells the step-up dialog to go to sign-in rather than stay open on an ended session.
            ? response()->json(['message' => $message, 'errors' => [$field => [$message]], 'signed_out' => true, 'redirect' => route('login')], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => self::secondsLeft($hour)])
            : redirect()->route('login'));
    }

    /**
     * @throws HttpResponseException
     * @throws ValidationException
     */
    private static function refuse(Request $request, string $field, string $message, int $seconds): never
    {
        if ($request->wantsJson()) {
            throw new HttpResponseException(response()->json(
                ['message' => $message, 'errors' => [$field => [$message]]],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $seconds],
            ));
        }

        throw ValidationException::withMessages([$field => $message]);
    }
}
