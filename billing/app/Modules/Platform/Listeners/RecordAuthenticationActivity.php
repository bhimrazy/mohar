<?php

namespace App\Modules\Platform\Listeners;

use App\Events\Core\StaffLoggedIn;
use App\Events\Core\StaffLoginFailed;
use App\Events\Core\StaffLoginFailuresThreshold;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Models\ImpersonationSession;
use App\Modules\Platform\Services\Impersonation;
use App\Modules\Platform\Services\LoginLockout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Last-login tracking, login lockout bookkeeping and the sign-in entries of the activity log.
 */
class RecordAuthenticationActivity
{
    /** Failures for one account within an hour that raise StaffLoginFailuresThreshold. */
    public const FAILURE_THRESHOLD = 10;

    public function __construct(private ActivityLogger $activity, private Impersonation $impersonation, private LoginLockout $lockout) {}

    public function login(Login $event): void
    {
        if ($this->impersonation->isSwitching() || ! $event->user instanceof User) {
            return;
        }

        $user = $event->user;
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();
        $this->lockout->clear($user->email, (string) request()->ip());

        $this->activity->log($user->is_staff ? 'Staff member signed in' : 'Client login signed in', $user, event: 'auth.login', actor: $user);

        if ($user->is_staff) {
            StaffLoggedIn::dispatch($user->id);
        }
    }

    public function failed(Failed $event): void
    {
        $email = is_string($event->credentials['email'] ?? null) ? $event->credentials['email'] : '';
        $user = $event->user instanceof User ? $event->user : null;

        $this->lockout->recordFailure($email, (string) request()->ip());
        $this->activity->log('Failed sign-in attempt', $user, properties: ['email' => $email], event: 'auth.login_failed');

        if ($user?->is_staff) {
            StaffLoginFailed::dispatch($user->id);
            $key = 'login-failures-user:'.$user->id;
            RateLimiter::hit($key, 3600);

            if (RateLimiter::attempts($key) === self::FAILURE_THRESHOLD) {
                StaffLoginFailuresThreshold::dispatch($user->id, self::FAILURE_THRESHOLD);
            }
        } elseif ($user === null) {
            StaffLoginFailed::dispatch(null);
        }
    }

    public function logout(Logout $event): void
    {
        $state = request()->hasSession() ? request()->session()->get(Impersonation::SESSION_KEY) : null;

        if (is_array($state) && isset($state['id'])) {
            ImpersonationSession::query()->whereKey($state['id'])->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'logout']);
        }
    }
}
