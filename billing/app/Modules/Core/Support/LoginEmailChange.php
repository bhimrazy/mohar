<?php

namespace App\Modules\Core\Support;

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Notifications\LoginEmailNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A login changing its own sign-in email after step-up (#388). The old address is told, with the new one masked,
 * and the new address must be verified again. A login without a password cannot pass step-up (ADR 0018 §4), so it
 * cannot change its email until it sets one.
 */
final class LoginEmailChange
{
    public function __construct(private SystemMailer $mailer, private ActivityLogger $activity) {}

    /**
     * The same rule as User::hasPassword() (#384), which develop does not have yet; use that once it lands.
     */
    public static function hasPassword(User $user): bool
    {
        return filled($user->getAuthPassword());
    }

    /**
     * The local part keeps its first and last character: enough for the owner to recognise the address, not enough
     * to read it from a notice that reached someone else.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $masked = mb_substr($local, 0, 1).'***'.(mb_strlen($local) > 2 ? mb_substr($local, -1) : '');

        return $domain === '' ? $masked : $masked.'@'.$domain;
    }

    /**
     * Changes the email under a lock, then tells the old address and mails the new one a verification link.
     *
     * @throws ValidationException when another login took the address meanwhile
     */
    public function change(User $user, string $newEmail): void
    {
        $newEmail = mb_strtolower(trim($newEmail));

        $old = DB::transaction(function () use ($user, $newEmail): ?string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->email === $newEmail) {
                return null;
            }

            if (User::query()->where('email', $newEmail)->whereKeyNot($locked->id)->exists()) {
                throw ValidationException::withMessages(['email' => __('The email has already been taken.')]);
            }

            $old = $locked->email;
            $locked->forceFill(['email' => $newEmail, 'email_verified_at' => null])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return $old;
        });

        if ($old === null) {
            return;
        }

        $this->activity->log('Login email changed', $user, properties: ['old_email' => $old, 'new_email' => $user->email], event: 'auth.email_changed', actor: $user);
        $this->noticeToOldAddress($user, $old);
        $user->sendEmailVerificationNotification();
    }

    private function noticeToOldAddress(User $user, string $old): void
    {
        $masked = self::mask($user->email);
        $context = [
            'notice' => ['actor' => $user->name, 'old_email' => $old, 'new_email' => $masked],
            'user' => ['first_name' => Str::before(trim($user->name), ' '), 'email' => $old],
        ];
        // Staff get the staff template (UpdateStaff sends it too); the occurrence key makes a retried request send once.
        $outcome = $this->mailer->send($user->is_staff ? 'staff.email_changed' : 'auth.email_changed', $old, $context, 'email-changed|'.$user->id.'|'.hash('sha256', $old.'|'.$user->email.'|'.now()->format('YmdHi')));

        if ($outcome === SystemMailOutcome::Unavailable) {
            Notification::route('mail', $old)->notify(new LoginEmailNotice(__('Your sign-in email was changed'), [
                __('The sign-in email of your account was changed from :old to :new.', ['old' => $old, 'new' => $masked]),
                __('If you didn\'t make this change, contact support straight away.'),
            ]));
        }
    }
}
