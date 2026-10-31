<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Enums\TemplateType;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Settings\SupportAddresses;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Applies the owner's sender rules to every outgoing email, including framework and module notifications
 * that don't go through templates: the default sender shows the company name (never APP_NAME) and uses
 * notifications.from.default when set; a message without a Reply-To gets the support address (never a
 * no-reply one; none when nothing is configured). Templated mail already sets both and is left alone.
 */
class BrandOutgoingMail
{
    public function __construct(private SupportAddresses $addresses) {}

    public function handle(MessageSending $event): void
    {
        $message = $event->message;
        $from = $message->getFrom()[0] ?? null;

        // Only the framework default sender (MAIL_FROM_ADDRESS with APP_NAME or no name) is rebranded.
        $isDefaultSender = $from === null || (mb_strtolower($from->getAddress()) === mb_strtolower((string) config('mail.from.address'))
            && in_array($from->getName(), ['', (string) config('mail.from.name')], true));

        if ($isDefaultSender) {
            $message->from(new Address($this->addresses->from(TemplateType::General), $this->addresses->fromName()));
        }

        if ($message->getReplyTo() === [] && ($address = $this->addresses->general()) !== null) {
            $message->replyTo(new Address($address));
        }

        // Framework notifications are system mail too (templated mail sets its own headers).
        $headers = $message->getHeaders();

        if (! $headers->has('X-Message-Ref')) {
            foreach (TemplatedMail::AUTOMATIC_HEADERS as $name => $value) {
                if (! $headers->has($name)) {
                    $headers->addTextHeader($name, $value);
                }
            }
        }
    }
}
