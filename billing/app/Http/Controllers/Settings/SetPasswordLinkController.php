<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Notifications\SetPasswordNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;

/**
 * "Email me a link to set a password" for a login that has none (ADR 0018 §4). Mails a link to the account's own
 * address that opens the signed-in set-password page. A login with a password gets 403; the answer never says more
 * than "sent", also when a link was created a moment ago and no new one is sent.
 */
class SetPasswordLinkController extends Controller
{
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        abort_if($user->hasPassword(), 403);

        $broker = Password::broker(config('fortify.passwords'));

        if (! $broker->getRepository()->recentlyCreatedToken($user)) {
            $user->notify(new SetPasswordNotification($broker->createToken($user)));
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'sent']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Check your email for a link to set a password.')]);

        return back();
    }
}
