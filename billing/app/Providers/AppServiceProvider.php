<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Automation\Automation;
use App\Support\Clients\ClientMergeRegistry;
use App\Support\Dashboard\Dashboard;
use App\Support\Navigation\Navigation;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingsRegistry;
use App\Support\Themes\ThemeRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Navigation::class);
        $this->app->singleton(Dashboard::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(SettingsRegistry::class);
        $this->app->singleton(GlobalSearch::class);
        $this->app->singleton(Automation::class);
        $this->app->singleton(ThemeRegistry::class);
        $this->app->singleton(ClientMergeRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureModules();
        $this->configureAuthMail();
    }

    /**
     * Laravel's own reset and verification mails (sent when no Notifications template handles them) carry
     * the "If this wasn't you" security line, like the templated ones.
     */
    protected function configureAuthMail(): void
    {
        ResetPassword::toMailUsing(function (CanResetPassword $notifiable, string $token): MailMessage {
            $url = ResetPassword::$createUrlCallback !== null
                ? (string) call_user_func(ResetPassword::$createUrlCallback, $notifiable, $token)
                : url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false));
            $broker = (string) config('auth.defaults.passwords', 'users');

            return (new MailMessage)
                ->subject(__('Reset Password Notification'))
                ->markdown('notifications::email', ['security' => true])
                ->line(__('You are receiving this email because we received a password reset request for your account.'))
                ->action(__('Reset Password'), $url)
                ->line(__('This password reset link will expire in :count minutes.', ['count' => config("auth.passwords.{$broker}.expire")]))
                ->line(__('If you did not request a password reset, no further action is required.'));
        });

        VerifyEmail::toMailUsing(fn (object $notifiable, string $url): MailMessage => (new MailMessage)
            ->subject(__('Verify Email Address'))
            ->markdown('notifications::email', ['security' => true])
            ->line(__('Please click the button below to verify your email address.'))
            ->action(__('Verify Email Address'), $url)
            ->line(__('If you did not create an account, no further action is required.')));
    }

    /**
     * Conventions shared by every module in app/Modules.
     */
    protected function configureModules(): void
    {
        Model::shouldBeStrict(! app()->isProduction());
        JsonResource::withoutWrapping();

        // Staff permissions ("module.area.action") are checked with Gate/@can/authorize().
        Gate::before(fn (User $user, string $ability): ?bool => $user->is_staff && str_contains($ability, '.') && $user->hasPermission($ability) ? true : null);

        Route::macro('adminArea', function (Closure $routes): void {
            Route::middleware(['auth', 'verified', 'staff'])->prefix('admin')->name('admin.')->group($routes);
        });

        Route::macro('clientArea', function (Closure $routes): void {
            Route::middleware(['auth', 'verified', 'client.area'])->prefix('client')->name('client.')->group($routes);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
