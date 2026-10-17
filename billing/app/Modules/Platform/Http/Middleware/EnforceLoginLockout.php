<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Services\LoginLockout;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects sign-in attempts for a locked email + IP pair before Fortify checks the password.
 */
class EnforceLoginLockout
{
    public function __construct(private LoginLockout $lockout) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('post') && $request->route()?->getName() === 'login.store') {
            $email = (string) $request->input('email', '');

            if ($this->lockout->isLocked($email, (string) $request->ip())) {
                throw ValidationException::withMessages([
                    'email' => __('Too many failed sign-in attempts. Try again in :minutes minutes.', [
                        'minutes' => (int) ceil($this->lockout->secondsRemaining($email, (string) $request->ip()) / 60),
                    ]),
                ]);
            }
        }

        return $next($request);
    }
}
