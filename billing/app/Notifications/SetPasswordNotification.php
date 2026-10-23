<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail for a login that has no password: the link opens the signed-in "set a password" page. It is not the
 * reset-password mail, which tells the reader to reset a password they do not have.
 */
class SetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Built on APP_URL, never on the request's Host header (see AccountLinks).
        $url = rtrim((string) config('app.url'), '/').route('password.set', ['token' => $this->token], false);
        $minutes = (int) config('auth.passwords.'.config('fortify.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('Set a password for your account'))
            ->markdown('notifications::email', ['security' => true])
            ->line(__('You asked to set a password for your account.'))
            ->action(__('Set a password'), $url)
            ->line(__('The link works for :count minutes. Sign in first if you are asked to.', ['count' => $minutes]))
            ->line(__('If you did not ask for this, no further action is required.'));
    }
}
