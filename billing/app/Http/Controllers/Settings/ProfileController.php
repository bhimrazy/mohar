<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Modules\Core\Support\LoginEmailChange;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // A login without a password can't change its email until it sets one (#388).
            'setPasswordUrl' => route('password.set-link'),
        ]);
    }

    /**
     * Update the user's name and email. A new email needs a recent password confirmation (#388), so a session alone
     * can't move the account to another mailbox; a login without a password must set one first.
     */
    public function update(ProfileUpdateRequest $request, LoginEmailChange $emails): RedirectResponse
    {
        $user = $request->user();
        $email = mb_strtolower(trim((string) $request->validated('email')));
        $json = $request->expectsJson() && ! $request->header('X-Inertia');
        $changing = $email !== $user->email;

        if ($changing) {
            if (! $user->hasPassword()) {
                $message = __('Set a password first. Your account has no password, and changing the email needs one.');

                if ($json) {
                    abort(response()->json(['message' => $message, 'code' => RequireRecentPasswordConfirmation::NO_PASSWORD_CODE], 423));
                }

                throw ValidationException::withMessages(['email' => $message]);
            }

            if (! RequireRecentPasswordConfirmation::recentlyConfirmed($request)) {
                // The same refusal as the password.recent middleware: 423 for JSON, the confirm dialog for Inertia.
                abort_if($json, 423, __('Password confirmation required.'));

                throw ValidationException::withMessages([RequireRecentPasswordConfirmation::ERROR_KEY => __('Confirm your password to continue.')]);
            }
        }

        $user->forceFill(['name' => $request->validated('name')])->save();

        if ($changing) {
            $result = $emails->change($user, $email);
            $problems = collect([
                $result->verificationSent ? null : __('we couldn\'t send the verification email. Use "Click here to re-send the verification email" below to try again'),
                $result->oldAddressTold ? null : __('we couldn\'t email your previous address about the change'),
            ])->filter();
            Inertia::flash('toast', $problems->isEmpty()
                ? ['type' => 'success', 'message' => __('Email changed. We sent a verification link to :email.', ['email' => $user->email])]
                : ['type' => 'warning', 'message' => __('Email changed and saved, but :problems.', ['problems' => $problems->implode(__(', and '))])]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);
        }

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
