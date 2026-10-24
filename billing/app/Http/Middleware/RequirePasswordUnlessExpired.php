<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\RequirePassword;

/**
 * Laravel's `password.confirm` (RequirePassword), bound in its place: a login whose one-time password has expired
 * counts as unconfirmed whatever the session holds (#459, ADR 0018 amendment). With `password.recent`, which checks the
 * same in recentlyConfirmed(), every route that asks for a password confirmation refuses such a login, and the
 * confirm page itself refuses the expired password, so it cannot confirm again.
 */
class RequirePasswordUnlessExpired extends RequirePassword
{
    protected function shouldConfirmPassword($request, $passwordTimeoutSeconds = null)
    {
        if ($request->user()?->passwordExpired()) {
            return true;
        }

        return parent::shouldConfirmPassword($request, $passwordTimeoutSeconds);
    }
}
