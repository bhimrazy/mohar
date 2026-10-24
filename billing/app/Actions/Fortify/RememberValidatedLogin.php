<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * Fortify's two-factor step checks the password (one bcrypt verification); its next step, AttemptToAuthenticate,
 * checks it again. This step keeps the user it already validated on the request so the next step can sign that
 * user in without a second verification. The hash cost is unchanged; a wrong password still fails here.
 */
class RememberValidatedLogin extends RedirectIfTwoFactorAuthenticatable
{
    public const ATTRIBUTE = 'login.validated_user';

    protected function validateCredentials($request)
    {
        $user = parent::validateCredentials($request);
        // Before the two-factor challenge, which signs in without this pipeline's later steps.
        RefuseExpiredPassword::check($user);
        $request->attributes->set(self::ATTRIBUTE, $user);

        return $user;
    }
}
