<?php

namespace App\Modules\Platform\Listeners;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Models\ImpersonationSession;
use App\Modules\Platform\Services\Impersonation;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Last-login tracking and the login/logout entries of the activity log.
 */
class RecordAuthenticationActivity
{
    public function __construct(private ActivityLogger $activity, private Impersonation $impersonation) {}

    public function login(Login $event): void
    {
        if ($this->impersonation->isSwitching() || ! $event->user instanceof User) {
            return;
        }

        $user = $event->user;
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();

        if ($user->is_staff) {
            $this->activity->log("Staff {$user->email} signed in", $user, event: 'auth.login', actor: $user);
        }
    }

    public function failed(Failed $event): void
    {
        $email = is_string($event->credentials['email'] ?? null) ? $event->credentials['email'] : null;

        $this->activity->log('Failed sign-in attempt'.($email ? " for {$email}" : ''), $event->user instanceof User ? $event->user : null, properties: ['email' => $email], event: 'auth.login_failed');
    }

    public function logout(Logout $event): void
    {
        $state = request()->hasSession() ? request()->session()->get(Impersonation::SESSION_KEY) : null;

        if (is_array($state) && isset($state['id'])) {
            ImpersonationSession::query()->whereKey($state['id'])->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'logout']);
        }
    }
}
