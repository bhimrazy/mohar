<?php

namespace App\Actions\Fortify;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Actions\AttemptToAuthenticate;

/**
 * Signs in the user that RememberValidatedLogin validated earlier in the same request, instead of verifying the
 * password a second time. Because it logs the user in directly, the Attempting and Validated events that
 * SessionGuard::attempt() fires no longer fire on a successful sign-in (Login and Failed still fire once; nothing in
 * the app listens to the other two). Without such a user (a custom pipeline, a different earlier step) it falls back to
 * Fortify's own attempt, so the password is always verified at least once.
 */
class SignInValidatedUser extends AttemptToAuthenticate
{
    public function handle($request, $next)
    {
        $user = $request->attributes->get(RememberValidatedLogin::ATTRIBUTE);

        if ($user instanceof Authenticatable) {
            $this->guard->login($user, $request->boolean('remember'));

            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
