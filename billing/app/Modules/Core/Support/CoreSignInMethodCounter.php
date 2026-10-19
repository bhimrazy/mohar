<?php

namespace App\Modules\Core\Support;

use App\Contracts\Auth\SignInMethodCounter;
use App\Models\User;

/**
 * Counts a login's password and passkeys (ADR 0018 §8). Modules that add a method decorate this binding.
 */
final class CoreSignInMethodCounter implements SignInMethodCounter
{
    public function count(User $user): int
    {
        return ($user->hasPassword() ? 1 : 0) + $user->passkeys()->count();
    }
}
