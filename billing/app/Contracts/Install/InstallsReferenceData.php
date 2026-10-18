<?php

namespace App\Contracts\Install;

use App\Data\Install\ReferenceDataResult;

/**
 * Reference data a module needs on every install, production included: rows the application cannot work without
 * (currencies, system roles, default email templates). Never demo data, users, credentials or network calls.
 *
 * Registered in bootModule() and run in order by `php artisan app:install` and by DatabaseSeeder:
 *
 *   app(ReferenceDataRegistry::class)->register('notifications_templates', DefaultTemplates::class, order: 100);
 *
 * install() runs inside a database transaction and must be idempotent: on an unchanged database a second run
 * creates and updates nothing. Rows the operator owns after install (a currency's rate, a template's wording) are
 * created when missing and then left alone; rows the system owns (system roles) are brought up to date.
 */
interface InstallsReferenceData
{
    /**
     * The registry key, matching [a-z0-9_]{1,50} and prefixed with the module key, e.g. "core_currencies".
     */
    public function key(): string;

    /**
     * A short label for the install summary, e.g. "Currencies".
     */
    public function label(): string;

    public function install(): ReferenceDataResult;
}
