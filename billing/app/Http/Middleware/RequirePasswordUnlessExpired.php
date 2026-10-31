<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\RequirePassword;

/**
 * Laravel's `password.confirm` (RequirePassword), bound in its place, with the step-up rule of `password.recent`:
 * a password confirmation (never a passkey one) within auth.step_up_seconds, refused for a login without a password
 * or with an expired one-time password (#459, ADR 0018 §4). Fortify puts this on passkey registration and removal
 * and on every two-factor route, so those need step-up too. Unlike `password.recent`, it sends a browser to the
 * confirm page and back.
 */
class RequirePasswordUnlessExpired extends RequirePassword
{
    protected function shouldConfirmPassword($request, $passwordTimeoutSeconds = null)
    {
        return ! RequireRecentPasswordConfirmation::recentlyConfirmed($request);
    }
}
