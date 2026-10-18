<?php

namespace App\Modules\Notifications\Console\Commands;

use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Installs missing system templates in the default language. Safe in production (reference data, no demo
 * accounts) and idempotent: staff edits are never overwritten.
 */
#[Signature('notifications:install-templates')]
#[Description('Install missing system email templates (never overwrites edited text)')]
class InstallTemplates extends Command
{
    public function handle(TemplateCatalog $catalog, Settings $settings): int
    {
        $created = $catalog->install((string) $settings->get('notifications.default_locale', 'en'));
        $this->components->info("Installed {$created} template texts.");

        return self::SUCCESS;
    }
}
