<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A plain security notice when no template system takes it (SystemMailer returned Unavailable): to the old address
 * after a sign-in email change (template auth.email_changed), or to an administrator given a new one-time password
 * (staff.password_reissued).
 */
class LoginEmailNotice extends Notification
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
            // Lines are parsed as Markdown and hold addresses the user typed: escape them so they render literally.
            $message->line(MailText::escape($line));
        }

        return $message;
    }
}
