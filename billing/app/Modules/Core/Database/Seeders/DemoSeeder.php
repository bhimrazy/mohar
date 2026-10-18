<?php

namespace App\Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Base for every module seeder. They create demo data (admin@example.com and demo@example.com with the password
 * "password", demo clients, servers, invoices and tickets), so they refuse to run in production, whether called
 * through DatabaseSeeder or directly with `db:seed --class=...`.
 */
abstract class DemoSeeder extends Seeder
{
    public const PRODUCTION_ERROR = 'Demo seeders never run in production: they create accounts with a known password. '
        .'Production reference data (currencies, system roles) and the first administrator come from the install path tracked in GitHub issue #47.';

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
