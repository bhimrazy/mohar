<?php

namespace App\Modules\Notifications\Database\Seeders;

use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Database\Seeder;

class NotificationsSeeder extends Seeder
{
    public function run(TemplateCatalog $catalog, Settings $settings): void
    {
        $catalog->install((string) $settings->get('notifications.default_locale', 'en'));
    }
}
