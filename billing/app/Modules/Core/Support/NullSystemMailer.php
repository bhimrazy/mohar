<?php

namespace App\Modules\Core\Support;

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;

/**
 * Default SystemMailer when the Notifications module is absent: takes nothing, so callers send their own fallback.
 */
class NullSystemMailer implements SystemMailer
{
    public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
    {
        return SystemMailOutcome::Unavailable;
    }
}
