<?php

namespace App\Modules\Platform\Services;

use App\Contracts\Auth\SignInGate;
use App\Models\User;

/**
 * The password login's refusals for every other sign-in method (ADR 0018 §2): a disabled login (EnsureActiveUser), a
 * client login whose every account is closed, and the email + IP lockout (LoginLockout), with the same messages.
 */
final class PlatformSignInGate implements SignInGate
{
    public function __construct(private LoginLockout $lockout) {}

    public function refusal(User $user, string $ip): ?string
    {
        if (! $user->is_active) {
            return __('Your account has been disabled.');
        }

        if ($this->lockout->isLocked($user->email, $ip)) {
            return __('Too many failed sign-in attempts. Try again in :minutes minutes.', [
                'minutes' => (int) ceil($this->lockout->secondsRemaining($user->email, $ip) / 60),
            ]);
        }

        if (! $user->is_staff && $user->clients()->exists() && ! $user->clients()->where('status', '!=', 'closed')->exists()) {
            return __('This account is closed. Contact us to reopen it.');
        }

        return null;
    }

    public function recordFailure(User $user, string $ip): void
    {
        $this->lockout->recordFailure($user->email, $ip);
    }
}
