<?php

namespace App\Modules\Platform\Support;

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Notifications\SecurityNotice;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Account security notices through the editable email templates (SystemMailer). A recipient the template system
 * can't serve (Notifications module absent, template not installed) gets the SecurityNotice notification instead;
 * one it refused gets nothing by another path.
 *
 * A notice that was not delivered (mail server down, template refused or suppressed) is written to the activity log,
 * so staff can see it, and never fails the request: the action it reports is already committed. Registered as
 * scoped, so a controller can ask whether anything failed during its request.
 */
final class AccountMail
{
    /** @var list<string> template keys of the notices not delivered in this request */
    private array $undelivered = [];

    public function __construct(private SystemMailer $mailer, private ActivityLogger $activity) {}

    /**
     * @param  iterable<User|string>  $recipients  logins or plain addresses
     * @param  array<string, string>  $notice  merge fields under "notice" (account, email, actor, …)
     * @param  string  $subject  what changed (e.g. "client:5|user:9"); with the notice and the minute it makes the
     *                           message key, so a retried request never sends the same notice twice
     * @param  list<string>  $fallbackLines  plain lines for the fallback notification
     * @return bool true when every recipient's notice was handed to the mail system; false when one was not
     *              delivered (logged as `account.notice_failed`). Only mail transport failures are caught.
     */
    public function notice(string $templateKey, iterable $recipients, array $notice, string $subject, string $fallbackSubject, array $fallbackLines, ?int $clientId = null): bool
    {
        $occurrence = $templateKey.'|'.sha1(json_encode([$subject, $notice, now()->format('YmdHi')], JSON_THROW_ON_ERROR));
        $allSent = true;

        foreach ($recipients as $recipient) {
            $address = $recipient instanceof User ? $recipient->email : $recipient;
            $outcome = $this->mailer->send($templateKey, $recipient, ['notice' => $notice], $occurrence, $clientId);

            if ($outcome === SystemMailOutcome::Refused || $outcome === SystemMailOutcome::Suppressed) {
                $allSent = false;
                $this->failed($templateKey, $address, $outcome->value, $clientId);

                continue;
            }

            if ($outcome !== SystemMailOutcome::Unavailable) {
                continue;
            }

            try {
                $notification = new SecurityNotice($fallbackSubject, $fallbackLines);
                $recipient instanceof User ? $recipient->notify($notification) : Notification::route('mail', $recipient)->notify($notification);
            } catch (TransportExceptionInterface $e) {
                report($e);
                $allSent = false;
                $this->failed($templateKey, $address, $e::class, $clientId);
            }
        }

        return $allSent;
    }

    /**
     * The toast for a finished action: the success one, or a warning with the same message when a notice about it
     * was not delivered.
     *
     * @param  array{type: string, message: string}  $success
     * @return array{type: string, message: string}
     */
    public function toast(array $success): array
    {
        return $this->undelivered === [] ? $success : [
            'type' => 'warning',
            'message' => $success['message'].' '.__('A security notice about it could not be delivered. The activity log has the details.'),
        ];
    }

    private function failed(string $templateKey, string $address, string $reason, ?int $clientId): void
    {
        $this->undelivered[] = $templateKey;
        $this->activity->log('Security notice not delivered', clientId: $clientId, properties: ['notice' => $templateKey, 'recipient' => $address, 'reason' => $reason], event: 'account.notice_failed');
    }
}
