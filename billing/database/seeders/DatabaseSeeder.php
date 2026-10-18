<?php

namespace Database\Seeders;

use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Providers\ModulesServiceProvider;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Run each enabled module's App\Modules\<Module>\Database\Seeders\<Module>Seeder, Core first.
     *
     * Every module seeder is a DemoSeeder (demo accounts with a known password), so this refuses to run in
     * production; each module seeder refuses on its own too, for `db:seed --class=...`.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(DemoSeeder::PRODUCTION_ERROR);
        }

        foreach (ModulesServiceProvider::enabledModules() as $module) {
            $seeder = "App\\Modules\\{$module}\\Database\\Seeders\\{$module}Seeder";

            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
