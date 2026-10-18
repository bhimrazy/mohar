<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Core\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Before any admin route: staff created with an admin-chosen password must change it, and when
 * security.staff_2fa_required is on, staff without confirmed 2FA must enrol (both on the security page).
 */
class EnforceStaffSecurity
{
    public function __construct(private Settings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_staff || ! str_starts_with((string) $request->route()?->getName(), 'admin.')) {
            return $next($request);
        }

        $message = match (true) {
            $user->must_change_password => trans('Choose a new password before using the admin area.'),
            (bool) $this->settings->get('security.staff_2fa_required', true) && ! $user->hasEnabledTwoFactorAuthentication() => trans('Set up two-factor authentication before using the admin area.'),
            default => null,
        };

        if ($message === null) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, is_string($message) ? $message : '');
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => $message]);

        return redirect()->route('security.edit');
    }
}
