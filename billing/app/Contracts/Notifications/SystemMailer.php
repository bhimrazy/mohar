<?php

namespace App\Contracts\Notifications;

use App\Enums\SystemMailOutcome;
use App\Models\User;

/**
 * Sends one of the editable email templates for mail that no domain event carries: invitations and account security
 * notices raised by Platform. Core binds a null implementation (always Unavailable); the Notifications module binds
 * the real one. A caller may send its own fallback only on Unavailable, never on Refused.
 */
interface SystemMailer
{
    /**
     * @param  User|string  $recipient  a login or a plain address
     * @param  array<string, mixed>  $context  merge fields; a one-time link is an App\Data\Notifications\SensitiveValue,
     *                                         dates may be Carbon instances (shown in the one mail format)
     * @param  string  $occurrenceKey  stable for this message, so a retry never sends it twice
     * @param  bool  $carriesSecret  the mail holds a one-time link: never copied to anyone
     */
    public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome;
}
