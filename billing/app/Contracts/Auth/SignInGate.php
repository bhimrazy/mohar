<?php

namespace App\Contracts\Auth;

use App\Models\User;

/**
 * The refusals a password login applies (disabled login, closed client account, lockout), for every other way of
 * signing in (ADR 0018 §2). Platform implements it; Core binds an allow-all default.
 *
 * @api
 */
interface SignInGate
{
    /**
     * The message a password login would show for this login and IP, or null when it may sign in.
     */
    public function refusal(User $user, string $ip): ?string;

    /**
     * A failed attempt by a sign-in method for this login, counted towards the same lockout as a failed password.
     */
    public function recordFailure(User $user, string $ip): void;
}
