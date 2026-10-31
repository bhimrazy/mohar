<?php

namespace App\Modules\Core\Support;

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Notifications\LoginEmailNotice;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Tells a login by email that a passkey was added to it, and logs it (security review 2026-10-31, M1): a session
 * taken over within the step-up window could add its own. The passkey's name is left out: whoever added it chose it.
 * The passkey is already saved, so a mail that does not go out is logged, never thrown.
 */
final class PasskeyAddedNotice
{
    public function __construct(private SystemMailer $mailer, private ActivityLogger $activity) {}

    public function __invoke(PasskeyRegistered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->activity->log('Passkey added', $user, properties: ['passkey_id' => $event->passkey->getKey()], event: 'auth.passkey_added', actor: $user);

        $context = ['user' => ['first_name' => trim(explode(' ', trim($user->name))[0]), 'email' => $user->email]];
        $outcome = $this->mailer->send($user->is_staff ? 'staff.passkey_added' : 'auth.passkey_added', $user, $context, 'passkey-added|'.$event->passkey->getKey());

        if ($outcome === SystemMailOutcome::Unavailable) {
            try {
                $user->notify(new LoginEmailNotice(__('A passkey was added to your account'), [
                    __('A passkey was added to the account :email. It can be used to sign in.', ['email' => $user->email]),
                    __('If you didn\'t add it, remove it in your security settings, change your password and contact support straight away.'),
                ]));

                return;
            } catch (TransportExceptionInterface $e) {
                report($e);
                $outcome = SystemMailOutcome::Refused;
            }
        }

        if ($outcome === SystemMailOutcome::Refused || $outcome === SystemMailOutcome::Suppressed) {
            $this->activity->log('Passkey notice not sent', $user, properties: ['outcome' => $outcome->value], event: 'auth.passkey_notice_failed', actor: $user);
        }
    }
}
