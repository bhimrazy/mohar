<?php

namespace App\Modules\Platform\Notifications;

use App\Contracts\Branding\BrandProvider;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Core\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotification extends Notification
{
    use Queueable;

    /**
     * @param  string|null  $acceptUrl  the accept link, built on APP_URL by the sender; null = built here
     */
    public function __construct(public StaffInvitation $invitation, public string $token, public ?string $acceptUrl = null) {}

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
            ->subject(__('You have been invited to the :app admin team', ['app' => app(BrandProvider::class)->brand()->name]))
            ->line(MailText::escape(__(':inviter invited you to join as :role.', [
                'inviter' => $this->invitation->inviter->name ?? __('An administrator'),
                'role' => $this->invitation->role->name,
            ])))
            ->action(__('Accept invitation'), $this->acceptUrl ?? route('platform.invitations.show', $this->token))
            ->line(__('This link expires on :date.', ['date' => $this->invitation->expires_at->toDayDateTimeString()]));
    }
}
