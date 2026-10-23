<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The page the set-password mail opens (ADR 0018 §4). It works for the signed-in login the token was made for and
 * only while that login has no password; it never asks for a current password. The guest reset flow is unchanged.
 */
class SetPasswordController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request, string $token): SymfonyResponse
    {
        // The token is in the URL: never leak it through the Referer header.
        $response = Inertia::render('settings/set-password', [
            'token' => $token,
            'valid' => $this->usable($request->user(), $token),
            'passwordRules' => PasswordRule::defaults()->toPasswordRulesString(),
        ])->toResponse($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $request->validate(['password' => $this->passwordRules()]);

        // Under a lock on the user row, so a link cannot be used twice at once.
        $set = User::query()->getConnection()->transaction(function () use ($request, $token): bool {
            $user = User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->usable($user, $token)) {
                return false;
            }

            $user->forceFill(['password' => $request->input('password'), 'remember_token' => null])->save();
            Password::broker(config('fortify.passwords'))->deleteToken($user);

            return true;
        });

        if (! $set) {
            throw ValidationException::withMessages(['token' => __('This link no longer works. Ask for a new one.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password set. You can now confirm sensitive actions.')]);

        return to_route('profile.edit');
    }

    private function usable(?User $user, string $token): bool
    {
        return $user !== null
            && ! $user->hasPassword()
            && Password::broker(config('fortify.passwords'))->tokenExists($user, $token);
    }
}
