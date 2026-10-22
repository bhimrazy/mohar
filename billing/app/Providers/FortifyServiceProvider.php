<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\PasswordConfirmedResponse;
use App\Http\Responses\PasswordResetFailedResponse;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\PasswordConfirmedResponse as PasswordConfirmedResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Known and unknown addresses get the same response on POST /forgot-password.
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(PasswordConfirmedResponseContract::class, PasswordConfirmedResponse::class);
        // …and the same error on POST /reset-password, whether or not the address has an account.
        $this->app->bind(FailedPasswordResetResponse::class, PasswordResetFailedResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn (Request $request) => Inertia::render('auth/confirm-password', [
            'download' => (bool) $request->session()->get('step_up_download', false),
        ]));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $username = $request->input(Fortify::username());
            // Non-string input (email[]=…) must reach validation (422), not crash the limiter.
            $throttleKey = Str::transliterate(Str::lower(is_string($username) ? $username : '').'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // POST /forgot-password: per IP and per email address (Fortify registers the route without a limiter).
        RateLimiter::for('password-reset-link', fn (Request $request): array => [
            Limit::perMinute(5)->by('ip|'.$request->ip()),
            Limit::perHour(5)->by('email|'.$this->throttleEmail($request)),
        ]);

        // POST /register: per IP and per address. The limiter runs before validation, so a known and an unknown
        // address get the same answer (security review 2026-10-19, M2).
        RateLimiter::for('register', fn (Request $request): array => [
            Limit::perHour(10)->by('register-ip|'.$request->ip()),
            Limit::perHour(5)->by('register-email|'.$this->throttleEmail($request)),
        ]);

        // POST /reset-password (submitting a token): per IP only. A per-address limit would let anyone lock a
        // victim out of a valid link by failing for their address; the token itself cannot be guessed. The request
        // that sends mail (POST /forgot-password) keeps its per-address limit.
        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(5)->by('reset-ip|'.$request->ip()));

        // Fortify registers these routes without a limiter of their own.
        $this->app->booted(function (): void {
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->getByName('password.email')?->middleware('throttle:password-reset-link');
            Route::getRoutes()->getByName('register.store')?->middleware('throttle:register');
            Route::getRoutes()->getByName('password.update')?->middleware('throttle:password-reset');
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }

    /**
     * The submitted address as a throttle key: lower-cased and transliterated, so case and look-alike variants share
     * one bucket; non-string input counts as empty and is left to validation.
     */
    private function throttleEmail(Request $request): string
    {
        $email = $request->input(Fortify::email());

        return Str::transliterate(Str::lower(trim(is_string($email) ? $email : '')));
    }
}
