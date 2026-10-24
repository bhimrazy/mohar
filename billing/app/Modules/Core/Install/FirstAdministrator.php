<?php

namespace App\Modules\Core\Install;

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Events\Core\StaffCreated;
use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Notifications\LoginEmailNotice;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\LoginSessions;
use App\Support\Security\PasswordPolicy;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Creates a full administrator from the install command: a verified, active staff member in the system role keyed
 * "full_administrator". The Platform module's EnforceStaffSecurity middleware then requires a new password (for a
 * one-time password) and two-factor setup (security.staff_2fa_required) before the admin area opens; the install
 * pre-flight warns in production when either safeguard is off.
 */
final class FirstAdministrator
{
    public const GENERATED_PASSWORD_LENGTH = 24;

    /** A generated one-time password stops signing in after this long (#60); app:admin-password issues a new one. */
    public const ONE_TIME_PASSWORD_HOURS = 24;

    /** @var (Closure(): string)|null */
    private static ?Closure $passwordGenerator = null;

    public function __construct(private ActivityLogger $activity, private SystemMailer $mailer) {}

    /**
     * Active staff whose role grants everything ("*"), whatever the role is called.
     */
    public function count(): int
    {
        $roleIds = Role::query()->orderBy('id')->get()
            ->filter(fn (Role $role): bool => in_array('*', $role->permissions, true))
            ->modelKeys();

        return $roleIds === [] ? 0 : User::query()
            ->where('is_staff', true)
            ->where('is_active', true)
            ->whereIn('role_id', $roleIds)
            ->count();
    }

    public function isFullAdministrator(User $user): bool
    {
        return $user->is_staff && $user->is_active && $user->role !== null && in_array('*', $user->role->permissions, true);
    }

    /**
     * Validation rules for an administrator password chosen at the prompt: the production rules in every environment.
     *
     * @return list<mixed>
     */
    public static function passwordRules(): array
    {
        return ['required', 'string', 'max:255', PasswordPolicy::production()];
    }

    /**
     * A random password that meets the production rules (it is never checked against the breach API: it is new).
     */
    public static function generatePassword(): string
    {
        if (self::$passwordGenerator !== null) {
            return (self::$passwordGenerator)();
        }

        do {
            $password = Str::password(self::GENERATED_PASSWORD_LENGTH);
        } while (! (preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password) && preg_match('/\d/', $password) && preg_match('/[^a-zA-Z\d]/', $password)));

        return $password;
    }

    /**
     * Replace the password generator (tests); null restores the random one.
     *
     * @param  (Closure(): string)|null  $generator
     */
    public static function generatePasswordsUsing(?Closure $generator): void
    {
        self::$passwordGenerator = $generator;
    }

    /**
     * Concurrent installs are serialised on the Full Administrator role row: the admin count and the email are checked
     * again under that lock, so two runs cannot both create the "first" administrator.
     *
     * @param  bool  $temporary  a generated one-time password: the administrator must choose a new one at first sign-in
     * @param  bool  $additional  create one even though a full administrator already exists
     *
     * @throws AdministratorRefused
     */
    public function create(string $name, string $email, string $password, bool $temporary, bool $additional = false): User
    {
        $user = DB::transaction(function () use ($name, $email, $password, $temporary, $additional): User {
            $role = Role::query()->where('key', SystemRoles::FULL_ADMINISTRATOR)->lockForUpdate()->first()
                ?? throw new LogicException('The Full Administrator system role is missing: install the reference data first.');

            if (User::query()->where('email', mb_strtolower(trim($email)))->exists()) {
                throw new AdministratorRefused("{$email} already belongs to an account. The installer does not promote existing accounts: use Admin → Staff, or choose another address.");
            }

            if (! $additional && $this->count() > 0) {
                throw new AdministratorRefused('A full administrator already exists, so none was created. Pass --additional-admin to add another.');
            }

            // Saving a new password clears must_change_password, so the flag is set in a second save.
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill([
                'is_staff' => true,
                'is_active' => true,
                'role_id' => $role->id,
                'email_verified_at' => now(),
                'must_change_password' => $temporary,
                'password_expires_at' => $temporary ? now()->addHours(self::ONE_TIME_PASSWORD_HOURS) : null,
            ])->save();

            $this->activity->log('Full administrator created by the installer', $user, properties: ['email' => $user->email, 'one_time_password' => $temporary], event: 'staff.created');

            return $user;
        });

        StaffCreated::dispatch($user->id);

        return $user;
    }

    /**
     * Replace a full administrator's password with a new generated one-time password (app:admin-password): the
     * recovery path once the first one expired, or when it was lost. Every way back in that came from the old
     * password ends too: stored sessions and remember-me cookies. The administrator's mailbox is told. Returns the
     * password, which is never stored.
     *
     * @param  string  $runBy  who ran it, for the audit entry and the notice (the OS user and host)
     *
     * @throws AdministratorRefused
     */
    public function reissue(User $user, string $runBy): string
    {
        if (! $this->isFullAdministrator($user)) {
            throw new AdministratorRefused("{$user->email} is not an active full administrator. Other staff reset their password with \"Forgot password\" or from Admin → Staff.");
        }

        $password = self::generatePassword();

        DB::transaction(function () use ($user, $password, $runBy): void {
            LoginSessions::rotateRememberToken($user);
            $user->forceFill([
                'password' => $password,
                'must_change_password' => true,
                'password_expires_at' => now()->addHours(self::ONE_TIME_PASSWORD_HOURS),
            ])->save();

            $this->activity->log('One-time password issued from the command line', $user, properties: ['email' => $user->email, 'run_by' => $runBy], event: 'staff.password_reissued');
        });

        LoginSessions::revoke($user);
        $this->tell($user, $runBy);

        return $password;
    }

    /**
     * The staff.password_reissued notice; the plain Core notice when no template system takes it. A notice not
     * delivered is logged and never undoes the reissue.
     */
    private function tell(User $user, string $runBy): void
    {
        $hours = self::ONE_TIME_PASSWORD_HOURS;
        $outcome = $this->mailer->send('staff.password_reissued', $user, ['notice' => ['run_by' => $runBy, 'expires_in' => trans_choice(':count hour|:count hours', $hours)]], 'password-reissued|'.$user->id.'|'.now()->format('YmdHis'));

        if ($outcome === SystemMailOutcome::Unavailable) {
            try {
                $user->notify(new LoginEmailNotice(__('A new one-time password was issued for your staff account'), [
                    __('A new one-time password for your staff account was issued on the server (:run_by). Your previous password and its "remember me" sign-ins no longer work.', ['run_by' => $runBy]),
                    __('If you didn\'t ask for this, tell another administrator straight away.'),
                ]));

                return;
            } catch (TransportExceptionInterface $e) {
                report($e);
                $outcome = SystemMailOutcome::Refused;
            }
        }

        if ($outcome === SystemMailOutcome::Refused || $outcome === SystemMailOutcome::Suppressed) {
            $this->activity->log('Security notice not delivered', $user, properties: ['notice' => 'staff.password_reissued', 'recipient' => $user->email, 'reason' => $outcome->value], event: 'account.notice_failed');
        }
    }
}
