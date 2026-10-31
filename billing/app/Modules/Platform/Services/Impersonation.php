<?php

namespace App\Modules\Platform\Services;

use App\Events\Core\ImpersonationEnded;
use App\Events\Core\ImpersonationStarted;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\ImpersonationSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * "Login as client": a staff member signs in to the client area as a user of a client account.
 *
 * Flow: start() records a pending session and returns a short-lived signed URL; enter() (only for the
 * staff member who started it, only once) switches the web guard to the client's user and flags the
 * session; end() switches back. Every step is activity-logged and entries written while impersonating
 * carry impersonator_id.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonation';

    /** True while the guard is being switched, so login listeners can ignore it. */
    private bool $switching = false;

    public function __construct(private ActivityLogger $activity, private Settings $settings) {}

    public function isSwitching(): bool
    {
        return $this->switching;
    }

    /**
     * @throws ValidationException when there is no suitable (non-staff) login on the account
     */
    public function start(User $staff, Client $client, ?int $userId, Request $request): string
    {
        $user = $userId !== null
            ? $client->users()->whereKey($userId)->first()
            : $client->users()->orderByDesc('client_user.is_owner')->orderBy('users.id')->first();

        if ($user === null) {
            throw ValidationException::withMessages(['user_id' => __('This client has no login to sign in as.')]);
        }

        if ($user->is_staff) {
            throw ValidationException::withMessages(['user_id' => __('Staff logins cannot be impersonated.')]);
        }

        if ($client->status === 'closed') {
            throw ValidationException::withMessages(['user_id' => __('Closed accounts cannot be used in the client area.')]);
        }

        $session = ImpersonationSession::query()->create([
            'staff_id' => $staff->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        return URL::temporarySignedRoute(
            'admin.platform.impersonation.enter',
            now()->addMinutes((int) config('platform.impersonation_link_minutes', 2)),
            ['impersonation' => $session->id],
        );
    }

    /**
     * Switch the current (staff) session to the client's user.
     */
    public function enter(ImpersonationSession $session, Request $request): void
    {
        abort_unless($session->staff_id === $request->user()?->getKey() && $session->started_at === null, 403);
        abort_if($session->user->is_staff || $session->client->status === 'closed', 403);

        $startedAt = now();
        $session->update(['started_at' => $startedAt]);
        $this->activity->log('Signed in as a client login', $session->client, $session->client_id, ['user_id' => $session->user_id], 'impersonation.started');

        $this->switchTo($session->user);

        $request->session()->forget(['auth.password_confirmed_at', RequireRecentPasswordConfirmation::SESSION_KEY]);
        ImpersonationStarted::dispatch($session->id, $session->staff_id, $session->client_id, $session->user_id);

        $request->session()->put([
            self::SESSION_KEY => ['id' => $session->id, 'staff_id' => $session->staff_id, 'started_at' => $startedAt->getTimestamp()],
            ActivityLogger::IMPERSONATOR_SESSION_KEY => $session->staff_id,
            ClientContext::SESSION_KEY => $session->client_id,
        ]);
    }

    /**
     * End the impersonation and sign the staff member back in. Returns the client id.
     */
    public function end(Request $request, string $reason = 'returned'): ?int
    {
        $session = $this->current($request);

        $request->session()->forget([self::SESSION_KEY, ActivityLogger::IMPERSONATOR_SESSION_KEY, ClientContext::SESSION_KEY]);

        if ($session === null) {
            return null;
        }

        $session->update(['ended_at' => now(), 'end_reason' => $reason]);
        $this->activity->log('Stopped signing in as a client login', $session->client, $session->client_id, ['reason' => $reason, 'user_id' => $session->user_id], 'impersonation.ended', $session->staff);
        ImpersonationEnded::dispatch($session->id, $session->staff_id, $session->client_id, $reason);

        if ($session->staff->is_active) {
            $this->switchTo($session->staff);
        } else {
            Auth::guard('web')->logout();
        }

        return $session->client_id;
    }

    private function switchTo(User $user): void
    {
        $this->switching = true;

        try {
            Auth::guard('web')->login($user);
        } finally {
            $this->switching = false;
        }
    }

    public function current(Request $request): ?ImpersonationSession
    {
        $state = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! is_array($state) || ! isset($state['id'])) {
            return null;
        }

        return ImpersonationSession::query()->whereNull('ended_at')->with(['staff', 'user', 'client'])->whereKey($state['id'])->first();
    }

    public function expiresAt(ImpersonationSession $session): CarbonImmutable
    {
        return ($session->started_at ?? CarbonImmutable::now())->addMinutes($this->maxMinutes());
    }

    /**
     * Session cap from the "Sign-in-as-client time limit" setting (falls back to config).
     */
    public function maxMinutes(): int
    {
        return max(1, (int) $this->settings->get('platform.impersonation_minutes', 60));
    }
}
