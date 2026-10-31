<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
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
     * pass a budget check made before any of them is counted (#523).
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
        $hourCount = RateLimiter::increment($hour, 3600);
        $minuteCount = RateLimiter::increment($minute, 60);

        if ($hourCount > self::PER_HOUR || $minuteCount > self::PER_MINUTE) {
            self::refund($hour, $minute);
            [$key, $message] = $hourCount > self::PER_HOUR ? [$hour, self::hourMessage($hour)] : [$minute, self::minuteMessage($minute)];

            self::refuse($request, $field, $message, RateLimiter::availableIn($key));
        }

        if ($compare()) {
            self::refund($hour, $minute);

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

    private static function refund(string $hour, string $minute): void
    {
        RateLimiter::decrement($hour, 3600);
        RateLimiter::decrement($minute, 60);
    }

    private static function hourMessage(string $hour): string
    {
        $minutes = max(1, (int) ceil(RateLimiter::availableIn($hour) / 60));

        return trans_choice('{1} Too many wrong passwords. Try again in :minutes minute.|[2,*] Too many wrong passwords. Try again in :minutes minutes.', $minutes, ['minutes' => $minutes]);
    }

    private static function minuteMessage(string $minute): string
    {
        return __('Too many password attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($minute)]);
    }

    /**
     * The session may be someone else's (M1's threat model), so it does not keep guessing from a fresh one either.
     * Signing out also cycles the remember-me token.
     *
     * @throws HttpResponseException
     */
    private static function endSession(User $user, Request $request, string $field, string $hour): never
    {
        app(ActivityLogger::class)->log('Signed out after too many wrong passwords', $user, self::activityClientId($user, $request), event: 'auth.password_guesses_exhausted', actor: $user);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = self::hourMessage($hour).' '.__('You have been signed out.');

        throw new HttpResponseException($request->wantsJson()
            ? response()->json(['message' => $message, 'errors' => [$field => [$message]]], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => RateLimiter::availableIn($hour)])
            : redirect()->route('login')->with('status', $message));
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
