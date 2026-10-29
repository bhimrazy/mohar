<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\RegisteredUserController as FortifyRegisteredUserController;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Fortify's sign-up, except that a mail transport failure while the verification email is sent (the synchronous
 * fallback, with no template system) no longer ends in an error page after the account is committed: the person is
 * signed in and told to resend it, and the failure is reported and logged (#520, as #443 does for staff actions).
 */
class RegisteredUserController extends FortifyRegisteredUserController
{
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        if (config('fortify.lowercase_usernames') && $request->has(Fortify::username())) {
            $request->merge([Fortify::username() => Str::lower($request->{Fortify::username()})]);
        }

        $user = $creator->create($request->all());

        try {
            event(new Registered($user));
        } catch (TransportExceptionInterface $e) {
            report($e);
            app(ActivityLogger::class)->log('Verification email not sent', $user, properties: ['email' => $user->email, 'reason' => $e::class], event: 'auth.email_verification_failed', actor: $user instanceof User ? $user : null);
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('Your account was created, but the verification email could not be sent. Resend it from the next page once mail works.')]);
        }

        $this->guard->login($user, $request->boolean('remember'));

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return app(RegisterResponse::class);
    }
}
