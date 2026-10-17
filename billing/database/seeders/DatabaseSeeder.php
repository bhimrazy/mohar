<?php

namespace Database\Seeders;

use App\Providers\ModulesServiceProvider;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run each enabled module's App\Modules\<Module>\Database\Seeders\<Module>Seeder, Core first.
     */
    public function run(): void
    {
        foreach (ModulesServiceProvider::enabledModules() as $module) {
            $seeder = "App\\Modules\\{$module}\\Database\\Seeders\\{$module}Seeder";

            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
