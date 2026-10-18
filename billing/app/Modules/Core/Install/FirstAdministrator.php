<?php

namespace App\Modules\Core\Install;

use App\Events\Core\StaffCreated;
use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Security\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Creates a full administrator from the install command. The account is a verified, active staff member in the
 * system "Full Administrator" role; two-factor setup is enforced at first sign-in by security.staff_2fa_required.
 */
final class FirstAdministrator
{
    public const GENERATED_PASSWORD_LENGTH = 24;

    public function __construct(private ActivityLogger $activity) {}

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
        do {
            $password = Str::password(self::GENERATED_PASSWORD_LENGTH);
        } while (! (preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password) && preg_match('/\d/', $password) && preg_match('/[^a-zA-Z\d]/', $password)));

        return $password;
    }

    /**
     * @param  bool  $temporary  a generated one-time password: the administrator must choose a new one at first sign-in
     */
    public function create(string $name, string $email, string $password, bool $temporary): User
    {
        $role = Role::query()->where('name', SystemRoles::FULL_ADMINISTRATOR)->where('is_system', true)->first()
            ?? throw new LogicException('The Full Administrator role is missing: install the reference data first.');

        $user = DB::transaction(function () use ($name, $email, $password, $temporary, $role): User {
            // Saving a new password clears must_change_password, so the flag is set in a second save.
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill([
                'is_staff' => true,
                'is_active' => true,
                'role_id' => $role->id,
                'email_verified_at' => now(),
                'must_change_password' => $temporary,
            ])->save();

            return $user;
        });

        $this->activity->log('Full administrator created by the installer', $user, properties: ['email' => $user->email, 'one_time_password' => $temporary], event: 'staff.created');
        StaffCreated::dispatch($user->id);

        return $user;
    }
}
