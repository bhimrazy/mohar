<?php

namespace App\Contracts\Auth;

use App\Models\User;

/**
 * Sends account-access mail (password reset, email verification). Core binds Laravel's own notifications;
 * the Notifications module rebinds it to send its editable, logged templates. Called by User — an event is
 * never used to choose who sends, and the token never travels in an event (ADR 0011, review PR #28).
 */
interface AuthMailer
{
    public function sendPasswordReset(User $user, #[\SensitiveParameter] string $token): void;

    public function sendEmailVerification(User $user): void;
}
