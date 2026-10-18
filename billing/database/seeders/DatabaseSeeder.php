<?php

namespace Database\Seeders;

use App\Providers\ModulesServiceProvider;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run each enabled module's App\Modules\<Module>\Database\Seeders\<Module>Seeder, Core first.
     *
     * The module seeders create demo data (admin@example.com and demo@example.com with the password
     * "password", demo clients, servers, invoices and tickets), so they never run in production.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Skipped: the module seeders create demo accounts and data, which must never exist in production.');

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
