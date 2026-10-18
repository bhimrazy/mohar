<?php

namespace App\Modules\Platform\Notifications;

use App\Modules\Platform\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security-event email (account email changed, 2FA reset, security policy changed). Sent to the
 * affected person and, for platform-wide changes, to every full administrator.
 */
class SecurityNotice extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(public string $subject, public array $lines) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject)->markdown('notifications::email', ['security' => true]);

        foreach ($this->lines as $line) {
            $message->line(MailText::escape($line));
        }

        return $message->line(__('If you did not expect this change, contact your administrator immediately.'));
    }
}
