<?php

namespace Database\Seeders;

use App\Modules\Core\Install\ReferenceDataInstaller;
use App\Modules\Core\Install\RegionalDefaults;
use App\Providers\ModulesServiceProvider;
use Illuminate\Database\Seeder;

use function Laravel\Prompts\warning;

class DatabaseSeeder extends Seeder
{
    /**
     * Install every module's reference data (the same installers `php artisan app:install` runs), then, outside
     * production only, each enabled module's demo seeder App\Modules\<Module>\Database\Seeders\<Module>Seeder,
     * Core first. Demo seeders create accounts with a known password; each refuses production on its own too
     * (DemoSeeder), for `db:seed --class=...`.
     */
    public function run(ReferenceDataInstaller $installer): void
    {
        if (app()->isProduction()) {
            // Production keeps what it has always done: no regional settings from a seed (`app:install` writes them).
            app(RegionalDefaults::class)->keepBuiltInDefaults();
            $installer->install();

            // A prompt note, not $this->command: it also prints when the seeder is invoked outside Artisan.
            warning('Production: reference data installed, demo data skipped. Create the first administrator with `php artisan app:install`.');

            return;
        }

        // The demo is a Nepal install: NPR, Asia/Kathmandu and the Nepal date format, like `app:install` with its defaults.
        app(RegionalDefaults::class)->choose(RegionalDefaults::COUNTRY, RegionalDefaults::CURRENCY, RegionalDefaults::TIMEZONE);
        $installer->install();

        foreach (ModulesServiceProvider::enabledModules() as $module) {
            $seeder = "App\\Modules\\{$module}\\Database\\Seeders\\{$module}Seeder";

            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
