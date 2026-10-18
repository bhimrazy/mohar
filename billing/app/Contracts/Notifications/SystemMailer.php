<?php

namespace App\Contracts\Notifications;

use App\Models\User;

/**
 * Sends one of the editable email templates for mail that no domain event carries: invitations and account security
 * notices raised by Platform. Core binds a null implementation; the Notifications module binds the real one. A
 * caller sends its own fallback when this returns false, so the mail is never lost when Notifications is absent.
 */
interface SystemMailer
{
    /**
     * @param  list<User|string>  $recipients  logins or plain addresses
     * @param  array<string, mixed>  $context  merge fields; a one-time link is an App\Data\Notifications\SensitiveValue
     * @param  string  $occurrenceKey  identifies this occurrence, so a retry never sends twice
     * @param  bool  $carriesSecret  the mail holds a one-time link: never copied to anyone
     * @return bool true when the template system took the mail for every recipient (queued, or suppressed on purpose)
     */
    public function send(string $templateKey, array $recipients, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): bool;
}
