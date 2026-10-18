<?php

namespace App\Modules\Platform\Support;

use App\Contracts\Notifications\SystemMailer;
use App\Models\User;
use App\Modules\Platform\Notifications\SecurityNotice;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Account security notices through the editable email templates (SystemMailer), with the SecurityNotice
 * notification as the fallback when the template system can't take them (Notifications module absent).
 */
final class AccountMail
{
    public function __construct(private SystemMailer $mailer) {}

    /**
     * @param  iterable<User|string>  $recipients  logins or plain addresses
     * @param  array<string, string>  $notice  merge fields under "notice" (account, email, actor, …)
     * @param  list<string>  $fallbackLines  plain lines for the fallback notification
     */
    public function notice(string $templateKey, iterable $recipients, array $notice, string $fallbackSubject, array $fallbackLines, ?int $clientId = null): void
    {
        $list = [];

        foreach ($recipients as $recipient) {
            $list[] = $recipient;
        }

        if ($list === [] || $this->mailer->send($templateKey, $list, ['notice' => $notice], $templateKey.'|'.Str::uuid(), $clientId)) {
            return;
        }

        $notification = new SecurityNotice($fallbackSubject, $fallbackLines);
        $users = array_values(array_filter($list, fn (User|string $recipient): bool => $recipient instanceof User));
        $addresses = array_values(array_filter($list, 'is_string'));

        if ($users !== []) {
            Notification::send($users, $notification);
        }

        if ($addresses !== []) {
            Notification::route('mail', $addresses)->notify($notification);
        }
    }
}
