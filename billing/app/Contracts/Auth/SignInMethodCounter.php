<?php

namespace App\Contracts\Auth;

use App\Models\User;

/**
 * How many ways a login has to sign in now (ADR 0018 §8): a password, its passkeys, and whatever modules add. Core
 * counts passwords and passkeys; a module that adds a method decorates the binding with its own count
 * (`$this->app->extend(SignInMethodCounter::class, …)`), so Core never reads a module's tables. Removing a method is
 * refused when the count would drop to 0. A password reset is recovery, not a method, so it is not counted.
 *
 * @api
 */
interface SignInMethodCounter
{
    public function count(User $user): int;
}
