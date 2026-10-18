<?php

namespace Database\Seeders;

use App\Modules\Core\Install\ReferenceDataInstaller;
use App\Providers\ModulesServiceProvider;
use Illuminate\Database\Seeder;

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
        $installer->install();

        if (app()->isProduction()) {
            $this->command?->warn('Production: reference data installed, demo data skipped. Create the first administrator with `php artisan app:install`.');

            return;
        }

        foreach (ModulesServiceProvider::enabledModules() as $module) {
            $seeder = "App\\Modules\\{$module}\\Database\\Seeders\\{$module}Seeder";

            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
