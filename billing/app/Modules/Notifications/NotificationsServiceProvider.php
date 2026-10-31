<?php

namespace App\Modules\Notifications;

use App\Contracts\Auth\AuthMailer;
use App\Contracts\Notifications\EmailTemplates;
use App\Contracts\Notifications\SystemMailer;
use App\Modules\Notifications\Boot\Automation;
use App\Modules\Notifications\Boot\Dashboard;
use App\Modules\Notifications\Boot\EncryptedStorage;
use App\Modules\Notifications\Boot\Listeners;
use App\Modules\Notifications\Boot\Navigation;
use App\Modules\Notifications\Boot\Permissions;
use App\Modules\Notifications\Boot\ReferenceData;
use App\Modules\Notifications\Boot\Settings;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Notifications\Services\TemplatedAuthMailer;
use App\Modules\Notifications\Services\TemplateDirectory;
use App\Modules\Notifications\Services\TemplatedSystemMailer;
use App\Modules\Notifications\Webhooks\HostResolver;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    /** Permissions that can exfiltrate data or hijack mail (BCC on password resets, arbitrary webhook URLs). */
    public const SENSITIVE_PERMISSIONS = ['notifications.templates.manage', 'notifications.webhooks.manage', 'notifications.delivery.manage'];

    public function moduleKey(): string
    {
        return 'notifications';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(TemplateCatalog::class);
        $this->app->bind(TemplateEngine::class);
        $this->app->singleton(HostResolver::class);
        $this->app->bind(EmailTemplates::class, TemplateDirectory::class);
        $this->app->bind(AuthMailer::class, TemplatedAuthMailer::class);
        $this->app->bind(SystemMailer::class, TemplatedSystemMailer::class);
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('notifications:retry-webhooks')->everyMinute()->withoutOverlapping()->onOneServer();
    }

    protected function bootModule(): void
    {
        foreach ([
            EncryptedStorage::class, Listeners::class, Navigation::class, Dashboard::class, Permissions::class,
            Settings::class, ReferenceData::class, Automation::class,
        ] as $boot) {
            $this->app->make($boot)($this->app);
        }
    }
}
