<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One failure budget per login for every check of its current password while signed in: step-up confirmation,
 * password change and account deletion (security review 2026-10-29, M1; #522). Only wrong passwords count, so
 * someone who knows the password never runs out, however short the step-up window. The hourly budget is keyed on
 * the login alone, so changing IP does not reset it; running out blocks these checks only, not sign-in or
 * "Forgot password".
 */
final class PasswordGuesses
{
    /** Wrong passwords per login and IP per minute (the sign-in form allows the same). */
    public const PER_MINUTE = 5;

    /** Wrong passwords per login per hour, from any IP. */
    public const PER_HOUR = 20;

    /**
     * Refuses the check, before the password is compared, once a budget is used up: 429 for JSON, otherwise a
     * validation error on the password field.
     *
     * @throws HttpResponseException
     * @throws ValidationException
     */
    public static function ensureAllowed(User $user, Request $request, string $field): void
    {
        $hour = self::hourKey($user);

        if (RateLimiter::tooManyAttempts($hour, self::PER_HOUR)) {
            $seconds = RateLimiter::availableIn($hour);

            self::refuse($request, $field, __('Too many wrong passwords. Try again in :minutes minutes.', ['minutes' => max(1, (int) ceil($seconds / 60))]), $seconds);
        }

        $minute = self::minuteKey($user, $request);

        if (RateLimiter::tooManyAttempts($minute, self::PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($minute);

            self::refuse($request, $field, __('Too many password attempts. Try again in :seconds seconds.', ['seconds' => $seconds]), $seconds);
        }
    }

    public static function failed(User $user, Request $request): void
    {
        RateLimiter::hit(self::minuteKey($user, $request), 60);
        RateLimiter::hit(self::hourKey($user), 3600);
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
