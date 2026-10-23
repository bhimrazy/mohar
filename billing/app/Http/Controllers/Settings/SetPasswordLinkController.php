<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;

/**
 * "Email me a link to set a password" for a login that has none (ADR 0018 §4). Sends the standard reset link to the
 * account's own address. A login with a password gets 403; the answer never says more than "sent", also when the
 * broker withholds a link because one was sent a moment ago.
 */
class SetPasswordLinkController extends Controller
{
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        abort_if($user->hasPassword(), 403);

        Password::broker(config('fortify.passwords'))->sendResetLink(['email' => $user->email]);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'sent']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Check your email for a link to set a password.')]);

        return back();
    }
}
