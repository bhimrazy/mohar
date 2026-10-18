<?php

namespace App\Modules\Notifications\Database\Seeders;

use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Services\TemplateCatalog;

/**
 * Development seeding. Production installs the templates with `php artisan notifications:install-templates`.
 */
class NotificationsSeeder extends DemoSeeder
{
    public function run(TemplateCatalog $catalog, Settings $settings): void
    {
        $catalog->install((string) $settings->get('notifications.default_locale', 'en'));
    }
}
