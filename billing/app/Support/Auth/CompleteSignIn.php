<?php

namespace App\Support\Auth;

use App\Contracts\Auth\SignInGate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only code outside Fortify that signs a user in (ADR 0018 §3). It applies the password login's refusals, sends a
 * login with confirmed two-factor authentication to Fortify's challenge, and otherwise logs in without "remember me",
 * regenerates the session and returns to the intended URL on APP_URL. No sign-in method is ever a second factor or a
 * password confirmation. Staff 2FA enrolment stays with Platform's EnforceStaffSecurity, which runs on every request.
 *
 * @api
 */
final class CompleteSignIn
{
    /** Fortify's challenge reads these; only this class writes them outside Fortify. */
    public const CHALLENGE_USER = 'login.id';

    public const CHALLENGE_REMEMBER = 'login.remember';

    public function __construct(private SignInGate $gate) {}

    /**
     * @param  string  $method  the sign-in method's key (callers audit with it)
     *
     * @throws ValidationException with the refusal, shown on the login page
     */
    public static function for(Request $request, User $user, string $method): RedirectResponse
    {
        return app(self::class)->complete($request, $user, $method);
    }

    public function complete(Request $request, User $user, string $method): RedirectResponse
    {
        $refusal = $this->gate->refusal($user, (string) $request->ip());

        if ($refusal !== null) {
            throw ValidationException::withMessages(['email' => $refusal])->redirectTo(route('login'));
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([self::CHALLENGE_USER => $user->getKey(), self::CHALLENGE_REMEMBER => false]);

            return redirect()->route('two-factor.login');
        }

        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();

        return redirect()->to($this->intended($request));
    }

    /**
     * The URL saved before the login page, only when it is on APP_URL; the dashboard otherwise.
     */
    private function intended(Request $request): string
    {
        $intended = (string) $request->session()->pull('url.intended', '');
        $root = rtrim((string) config('app.url'), '/');

        return $intended !== '' && ($intended === $root || Str::startsWith($intended, $root.'/')) ? $intended : route('dashboard');
    }
}
