<?php

namespace App\Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Base for every module demo seeder. They create demo data (admin@example.com and demo@example.com with the password
 * "password", demo clients, servers, invoices and tickets), so they refuse to run in production when called directly
 * with `db:seed --class=...`; DatabaseSeeder skips them there. Reference data never goes in a demo seeder: it is an
 * InstallsReferenceData registered with the ReferenceDataRegistry.
 */
abstract class DemoSeeder extends Seeder
{
    public const PRODUCTION_ERROR = 'Demo seeders never run in production: they create accounts with a known password. '
        .'Install reference data (currencies, system roles) and the first administrator with `php artisan app:install` (docs/devops/install.md).';

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __invoke(array $parameters = []): mixed
    {
        if (app()->isProduction()) {
            throw new RuntimeException(self::PRODUCTION_ERROR);
        }

        return parent::__invoke($parameters);
    }
}
