<?php

namespace App\Modules\Platform\Support;

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Platform\Notifications\SecurityNotice;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Account security notices through the editable email templates (SystemMailer). A recipient the template system
 * can't serve (Notifications module absent, template not installed) gets the SecurityNotice notification instead;
 * one it refused gets nothing by another path.
 */
final class AccountMail
{
    public function __construct(private SystemMailer $mailer) {}

    /**
     * @param  iterable<User|string>  $recipients  logins or plain addresses
     * @param  array<string, string>  $notice  merge fields under "notice" (account, email, actor, …)
     * @param  string  $subject  what changed (e.g. "client:5|user:9"); with the notice and the minute it makes the
     *                           message key, so a retried request never sends the same notice twice
     * @param  list<string>  $fallbackLines  plain lines for the fallback notification
     * @return bool false when a fallback mail failed (reported): the action it follows is already committed
     */
    public function notice(string $templateKey, iterable $recipients, array $notice, string $subject, string $fallbackSubject, array $fallbackLines, ?int $clientId = null): bool
    {
        $allSent = true;
        $occurrence = $templateKey.'|'.sha1(json_encode([$subject, $notice, now()->format('YmdHi')], JSON_THROW_ON_ERROR));

        foreach ($recipients as $recipient) {
            $outcome = $this->mailer->send($templateKey, $recipient, ['notice' => $notice], $occurrence, $clientId);

            if ($outcome !== SystemMailOutcome::Unavailable) {
                continue;
            }

            try {
                $notification = new SecurityNotice($fallbackSubject, $fallbackLines);
                $recipient instanceof User ? $recipient->notify($notification) : Notification::route('mail', $recipient)->notify($notification);
            } catch (Throwable $e) {
                report($e);
                $allSent = false;
            }
        }

        return $allSent;
    }
}
