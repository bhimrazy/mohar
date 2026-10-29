<?php

namespace App\Modules\Core\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends a login's other ways back in: its stored sessions (database driver) and its remember-me cookies.
 */
final class LoginSessions
{
    /**
     * Delete every stored session of the login, except the one named by $keep (the request that changed the
     * password). Other session drivers cannot be searched by user; EnsureActiveUser covers a disabled login there, and
     * a new remember token covers cookies.
     */
    public static function revoke(User $user, ?string $keep = null): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'))->where('user_id', $user->id)
                ->when($keep !== null, fn ($query) => $query->where('id', '!=', $keep))->delete();
        }
    }

    /**
     * A new remember token, so every remember-me cookie issued before stops signing in. Saved by the caller.
     */
    public static function rotateRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)]);
    }
}
