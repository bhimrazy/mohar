<?php

namespace App\Modules\Core\Support;

use App\Contracts\Notifications\SystemMailer;

/**
 * Default SystemMailer when the Notifications module is absent: takes nothing, so callers send their own fallback.
 */
class NullSystemMailer implements SystemMailer
{
    public function send(string $templateKey, array $recipients, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): bool
    {
        return false;
    }
}
