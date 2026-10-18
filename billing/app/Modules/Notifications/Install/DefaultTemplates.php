<?php

namespace App\Modules\Notifications\Install;

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Services\TemplateCatalog;

/**
 * The default email templates (reference data: production needs them). Missing templates and their default-language
 * text are created; anything that already exists — staff-edited wording included — is left alone.
 * Run by `php artisan app:install` and DatabaseSeeder through the ReferenceDataRegistry.
 */
final class DefaultTemplates implements InstallsReferenceData
{
    public const KEY = 'notifications_templates';

    public function __construct(private TemplateCatalog $catalog, private Settings $settings) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Email templates';
    }

    public function install(): ReferenceDataResult
    {
        return $this->catalog->install((string) $this->settings->get('notifications.default_locale', 'en'));
    }
}
