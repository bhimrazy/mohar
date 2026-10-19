<?php

namespace App\Modules\Core\Support;

use App\Contracts\Notifications\EmailTemplates;

/**
 * Default when the Notifications module is not installed: no templates to choose from (forms fall back to
 * a free-text key).
 */
class NullEmailTemplates implements EmailTemplates
{
    public function options(array $types = []): array
    {
        return [];
    }
}
