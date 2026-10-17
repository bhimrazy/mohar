<?php

namespace App\Modules\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password re-confirmation for money-moving and security-relevant staff actions (refunds, write-offs, credit
 * adjustments, manual payments, gateway settings, tax rules).
 *
 * Unlike Laravel's RequirePassword, the "intended" URL is the page the action was submitted from (an action URL
 * is usually POST-only), so after confirming the user lands back on that page and repeats the action.
 *
 * TODO(PR #3): also mark billing.refunds.*, billing.credit.manage, billing.gateways.manage and billing.tax.manage as
 * sensitive in PermissionRegistry once the platform's sensitive-permission tier is on develop.
 */
class ConfirmPasswordForAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (time() - $confirmedAt <= (int) config('auth.password_timeout', 10800)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __('Password confirmation required.')], 423);
        }

        redirect()->setIntendedUrl(url()->previous());

        return redirect()->route('password.confirm');
    }
}
