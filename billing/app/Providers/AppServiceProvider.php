<?php

namespace App\Providers;

use App\Http\Middleware\RequirePasswordUnlessExpired;
use App\Models\User;
use App\Modules\Core\Support\AccountLinks;
use App\Support\Automation\Automation;
use App\Support\Clients\ClientCurrencyUsage;
use App\Support\Clients\ClientMergeRegistry;
use App\Support\Currencies\ExchangeRateSources;
use App\Support\Currencies\PriceConverters;
use App\Support\Dashboard\Dashboard;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Events\WebhookEvents;
use App\Support\Install\ReferenceDataRegistry;
use App\Support\Integrations\Endpoints;
use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\Redactor;
use App\Support\Integrations\StoredSecrets;
use App\Support\Localization\CacheCatalogueCommand;
use App\Support\Localization\ClearCatalogueCommand;
use App\Support\Logging\RedactingFailedJobProvider;
use App\Support\Logging\RedactingLogManager;
use App\Support\Migration\ImportRegistry;
use App\Support\Navigation\Navigation;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Reports\ReportRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Security\EncryptedStorage;
use App\Support\Security\PasswordPolicy;
use App\Support\Seo\SitemapRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use App\Support\Settings\SettingLocks;
use App\Support\Settings\SettingsRegistry;
use App\Support\Themes\ThemeRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // password.confirm with the step-up rule (#459, #538); it ignores auth.password_timeout, so none is passed.
        $this->app->bind(RequirePassword::class, RequirePasswordUnlessExpired::class);
        // laravel-dompdf turns font subsetting off, so every PDF embedded the whole of DejaVu Sans: an invoice was
        // 858 KB instead of 23 KB and took about 40 ms longer to render (#566).
        $this->app->extend('dompdf.options', fn (array $options): array => [...$options, 'enable_font_subsetting' => true]);
        $this->app->singleton(Navigation::class);
        $this->app->singleton(Dashboard::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(ReportRegistry::class);
        $this->app->singleton(SettingsRegistry::class);
        $this->app->singleton(SettingLocks::class);
        $this->app->singleton(GlobalSearch::class);
        $this->app->singleton(Automation::class);
        $this->app->singleton(ThemeRegistry::class);
        $this->app->singleton(ClientMergeRegistry::class);
        $this->app->singleton(ClientCurrencyUsage::class);
        $this->app->singleton(ImportRegistry::class);
        $this->app->singleton(ReferenceDataRegistry::class);
        $this->app->singleton(EncryptedStorage::class);
        $this->app->singleton(ExchangeRateSources::class);
        $this->app->singleton(PriceConverters::class);
        $this->app->singleton(ProvisioningDriverRegistry::class);
        $this->app->singleton(RegistrarDriverRegistry::class);
        $this->app->singleton(SitemapRegistry::class);
        $this->app->singleton(Endpoints::class);
        $this->app->singleton(WebhookEvents::class);
        $this->app->singleton(StoredSecrets::class);

        // Every logger masks its records, and a failed job's exception is masked before it is stored (ADR 0023 §1.2, §1.3).
        $this->app->singleton('log', fn (Application $app): RedactingLogManager => new RedactingLogManager($app));
        $this->app->extend('queue.failer', fn (FailedJobProviderInterface $failer, Application $app): FailedJobProviderInterface => RedactingFailedJobProvider::wrap($failer, $app->make(Redactor::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->commands([CacheCatalogueCommand::class, ClearCatalogueCommand::class]);
        $this->optimizes(optimize: 'i18n:cache', clear: 'i18n:clear');

        // A driver that could not be built is retried by the next request or queued job, never in a loop within one.
        $forgetDriverFailures = function (): void {
            foreach ([RegistrarDriverRegistry::class, ProvisioningDriverRegistry::class] as $registry) {
                if ($this->app->resolved($registry)) {
                    $this->app->make($registry)->forgetTransientFailures();
                }
            }
        };
        Event::listen(JobProcessing::class, $forgetDriverFailures);
        $this->app->terminating($forgetDriverFailures);

        // Simulator endpoint overrides (ADR 0016) are logged once per boot; production ignores them.
        $this->app->make(Endpoints::class)->report();

        $this->configureDefaults();
        $this->configureModules();
        $this->configureAuthMail();

        // No driver call is in progress between a worker's jobs or after a response, so no redaction set may be either.
        Event::listen(Looping::class, fn () => RedactionScope::reset());
        $this->app->terminating(fn () => RedactionScope::reset());
    }

    /**
     * Laravel's own reset and verification mails (sent when no Notifications template handles them) carry
     * the "If this wasn't you" security line, like the templated ones.
     */
    protected function configureAuthMail(): void
    {
        // Account links are built on APP_URL, never on the request's Host header (host-header poisoning).
        ResetPassword::createUrlUsing(fn (CanResetPassword $notifiable, string $token): string => $notifiable instanceof User
            ? AccountLinks::passwordReset($notifiable, $token)
            : rtrim((string) config('app.url'), '/').route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false));
        VerifyEmail::createUrlUsing(fn (object $notifiable): string => $notifiable instanceof User
            ? AccountLinks::emailVerification($notifiable)
            : throw new \LogicException('Email verification is only sent to users.'));

        ResetPassword::toMailUsing(function (CanResetPassword $notifiable, string $token): MailMessage {
            $url = (string) call_user_func(ResetPassword::$createUrlCallback ?? fn (): string => '', $notifiable, $token);
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

        // Every generated URL (password reset, email verification, signed links, queued mail) uses APP_URL, never the
        // request's Host header, so a forged Host can't redirect a one-time link. Local development keeps the request
        // host so forwarded dev URLs work.
        if (! app()->environment('local') && filled(config('app.url'))) {
            URL::forceRootUrl((string) config('app.url'));

            if (str_starts_with((string) config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction() ? PasswordPolicy::production() : null);
    }
}
