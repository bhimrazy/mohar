<?php

namespace App\Contracts\Install;

use App\Data\Install\ReferenceDataResult;

/**
 * Provider contract (SPI, ADR 0010): implemented by modules and packages, so it never gains a method. A new ability
 * (an upgrade preview, a drift report) is a separate capability interface an installer may also implement.
 *
 * Reference data a module needs on every install, production included: rows the application cannot work without
 * (currencies, system roles, default email templates). Never demo data, users, credentials or network calls.
 *
 * Registered in bootModule() and run in order by `php artisan app:install` and by DatabaseSeeder, each installer in
 * its own database transaction:
 *
 *   app(ReferenceDataRegistry::class)->register('notifications_templates', DefaultTemplates::class, order: 100);
 *
 * install() must be idempotent: on an unchanged database a second run creates and updates nothing. Two kinds of rows:
 * - rows the operator owns after install (currencies, template wording) are created on the first install only; a
 *   later run never re-creates one staff deleted and never changes one staff edited;
 * - rows the system owns (system roles) are found by a stable key, never by a display name staff can change, and are
 *   brought back to their definition, with an audit entry for every change.
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
