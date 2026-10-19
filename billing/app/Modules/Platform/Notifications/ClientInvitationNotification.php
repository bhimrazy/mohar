<?php

namespace App\Modules\Platform\Notifications;

use App\Modules\Core\Support\BusinessCalendar;
use App\Modules\Core\Support\DateFormats;
use App\Modules\Platform\Support\MailText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fallback for the client-login invitation when the Notifications module is absent (it sends the editable
 * auth.user_invitation template otherwise). The accept link is built on APP_URL by the sender.
 */
class ClientInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public string $account, public string $acceptUrl, public CarbonInterface $expiresAt) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You\'re invited to a client account'))
            ->line(MailText::escape(__('You\'ve been given access to the :account account. Accept the invitation to choose your password.', ['account' => $this->account])))
            ->action(__('Accept invitation'), $this->acceptUrl)
            ->line(__('This link works once, until :date.', ['date' => CarbonImmutable::instance($this->expiresAt)->setTimezone(app(BusinessCalendar::class)->timezone())->format(DateFormats::current().', H:i T')]));
    }
}
