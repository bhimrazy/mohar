<?php

namespace App\Modules\Platform\Notifications;

use App\Modules\Core\Support\MailText;
use App\Modules\Platform\Support\MailServerSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Send test email" on Admin → Settings → Mail server. Sent at once through the test mailer, outside the template
 * system on purpose: it checks the transport alone, so a template problem cannot hide or cause a failure.
 */
class MailServerTest extends Notification
{
    public function __construct(public string $brand, public string $sentBy) {}

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
            ->mailer(MailServerSettings::TEST_MAILER)
            ->subject(__('Test email from :brand', ['brand' => $this->brand]))
            ->markdown('notifications::email')
            ->line(MailText::escape(__(':name sent this test from the mail server settings of :brand.', ['name' => $this->sentBy, 'brand' => $this->brand])))
            ->line(__('If you can read this, email from the billing system is reaching its recipients. No reply is needed.'));
    }
}
