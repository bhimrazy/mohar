<?php

namespace App\Modules\Support;

use App\Contracts\Support\PublicHelpDirectory;
use App\Contracts\Support\TicketStaffDirectory;
use App\Modules\Support\Boot\Automation;
use App\Modules\Support\Boot\Dashboard;
use App\Modules\Support\Boot\MergeFields;
use App\Modules\Support\Boot\Navigation;
use App\Modules\Support\Boot\Permissions;
use App\Modules\Support\Boot\RateLimiters;
use App\Modules\Support\Boot\Search;
use App\Modules\Support\Boot\Settings;
use App\Modules\Support\Boot\Sitemap;
use App\Modules\Support\Boot\Webhooks;
use App\Modules\Support\Services\SupportPublicHelp;
use App\Modules\Support\Services\SupportTicketStaff;
use App\Modules\Support\Services\TicketAccess;
use App\Modules\Support\Settings\SupportSettings;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class SupportServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'support';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(SupportSettings::class);
        $this->app->scoped(TicketAccess::class);
        $this->app->bind(PublicHelpDirectory::class, SupportPublicHelp::class);
        $this->app->bind(TicketStaffDirectory::class, SupportTicketStaff::class);
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('support:auto-close')->hourly()->withoutOverlapping()->onOneServer();
    }

    protected function bootModule(): void
    {
        foreach ([
            Webhooks::class, Sitemap::class, RateLimiters::class, Navigation::class, Dashboard::class,
            Permissions::class, Settings::class, Search::class, MergeFields::class, Automation::class,
        ] as $boot) {
            $this->app->make($boot)($this->app);
        }
    }
}
