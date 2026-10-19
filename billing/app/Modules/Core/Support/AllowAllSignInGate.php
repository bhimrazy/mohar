<?php

namespace App\Modules\Core\Support;

use App\Contracts\Auth\SignInGate;
use App\Models\User;

/**
 * The SignInGate when Platform (which owns the login rules) is not installed: nothing is refused.
 */
final class AllowAllSignInGate implements SignInGate
{
    public function refusal(User $user, string $ip): ?string
    {
        return null;
    }

    public function recordFailure(User $user, string $ip): void {}
}
