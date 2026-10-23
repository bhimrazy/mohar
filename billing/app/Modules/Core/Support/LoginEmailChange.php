<?php

namespace App\Modules\Core\Support;

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Notifications\LoginEmailNotice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * A login changing its own sign-in email after step-up (#388). The old address is told, with the new one masked,
 * and the new address must be verified again. A login without a password cannot pass step-up (ADR 0018 §4), so it
 * cannot change its email until it sets one.
 */
final class LoginEmailChange
{
    public function __construct(private SystemMailer $mailer, private ActivityLogger $activity) {}

    /**
     * The local part keeps its first character, and its last one only when it has 4 or more: enough for the owner to
     * recognise the address, not enough to read it from a notice that reached someone else.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $masked = mb_substr($local, 0, 1).'***'.(mb_strlen($local) > 3 ? mb_substr($local, -1) : '');

        return $domain === '' ? $masked : $masked.'@'.$domain;
    }

    /**
     * Changes the email under a lock, mails the new address a verification link, then tells the old address.
     *
     * Mail is sent after the commit, and a mail transport failure (server down) is reported, not thrown: it must not
     * turn a saved change into an error page. The result says which mail did not go, so the caller can tell the person.
     *
     * @throws ValidationException when another login took the address meanwhile
     */
    public function change(User $user, string $newEmail): LoginEmailChanged
    {
        $newEmail = mb_strtolower(trim($newEmail));
        $taken = ValidationException::withMessages(['email' => __('The email has already been taken.')]);

        try {
            $old = DB::transaction(function () use ($user, $newEmail, $taken): ?string {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);

                if ($locked->email === $newEmail) {
                    return null;
                }

                if (User::query()->where('email', $newEmail)->whereKeyNot($locked->id)->exists()) {
                    throw $taken;
                }

                $old = $locked->email;
                $locked->forceFill(['email' => $newEmail, 'email_verified_at' => null])->save();
                $user->setRawAttributes($locked->getAttributes(), true);
                $this->activity->log('Login email changed', $user, properties: ['old_email' => $old, 'new_email' => $newEmail], event: 'auth.email_changed', actor: $user);

                return $old;
            });
        } catch (UniqueConstraintViolationException) {
            // Another login committed the address between the check and the write; the unique index decides.
            throw $taken;
        }

        if ($old === null) {
            return new LoginEmailChanged(verificationSent: true, oldAddressTold: true);
        }

        return new LoginEmailChanged($this->sendVerification($user), $this->noticeToOldAddress($user, $old));
    }

    /**
     * Mails the verification link; false (reported) when the mail transport failed. The person can resend it from
     * Settings → Profile.
     */
    public function sendVerification(User $user): bool
    {
        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (TransportExceptionInterface $e) {
            report($e);
            $this->activity->log('Verification email not sent', $user, properties: ['email' => $user->email, 'reason' => $e::class], event: 'auth.email_verification_failed');

            return false;
        }
    }

    private function noticeToOldAddress(User $user, string $old): bool
    {
        $masked = self::mask($user->email);
        $context = [
            'notice' => ['actor' => $user->name, 'old_email' => $old, 'new_email' => $masked],
            'user' => ['first_name' => Str::before(trim($user->name), ' '), 'email' => $old],
        ];
        // Staff get the staff template (UpdateStaff sends it too).
        $outcome = $this->mailer->send($user->is_staff ? 'staff.email_changed' : 'auth.email_changed', $old, $context, 'email-changed|'.$user->id.'|'.hash('sha256', $old.'|'.$user->email.'|'.now()->format('YmdHi')));

        if ($outcome === SystemMailOutcome::Unavailable) {
            try {
                Notification::route('mail', $old)->notify(new LoginEmailNotice(__('Your sign-in email was changed'), [
                    __('The sign-in email of your account was changed from :old to :new.', ['old' => $old, 'new' => $masked]),
                    __('If you didn\'t make this change, contact support straight away.'),
                ]));

                return true;
            } catch (TransportExceptionInterface $e) {
                // The change is committed: a down mail server must not turn it into an error page.
                report($e);
                $outcome = SystemMailOutcome::Refused;
            }
        }

        if ($outcome === SystemMailOutcome::Refused || $outcome === SystemMailOutcome::Suppressed) {
            $this->activity->log('Login email change notice not sent', $user, properties: ['outcome' => $outcome->value, 'old_email' => $old], event: 'auth.email_change_notice_failed', actor: $user);

            return false;
        }

        return true;
    }
}
