<?php

namespace App\Models;

use App\Contracts\Auth\AuthMailer;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Role;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_staff
 * @property int|null $role_id
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $theme_preference
 * @property string|null $density_preference
 * @property string|null $last_login_ip
 * @property CarbonInterface|null $invited_at
 * @property bool $must_change_password
 * @property CarbonInterface|null $password_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_staff' => false,
        'is_active' => true,
    ];

    /**
     * Changing the password records when and clears a pending forced change (admin-set temporary password). Changing
     * the email of an existing client login (not staff: the test LoginImporter::linkable() uses) removes its
     * verification, unless the same save sets it: "verified since" then also means "unchanged since" (the client
     * import links a login only when that is 30 days or more). Staff email changes follow UpdateStaff's own rule (an
     * admin-initiated change clears verification, a self-change keeps it). A query-builder update() bypasses this hook.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
                $user->must_change_password = false;
            }

            if ($user->exists && ! $user->is_staff && $user->isDirty('email') && ! $user->isDirty('email_verified_at')) {
                $user->email_verified_at = null;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_staff' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * Emails are stored lowercase on every write path (registration, profile, staff edits) so unique
     * indexes behave the same on PostgreSQL as on SQLite.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Client accounts this login can act for.
     *
     * @return BelongsToMany<Client, $this>
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)->withPivot(['is_owner', 'permissions'])->withTimestamps();
    }

    /**
     * Account-access mail goes through the AuthMailer contract: Laravel's notifications by default, the
     * Notifications module's templates when it is installed.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        app(AuthMailer::class)->sendPasswordReset($this, $token);
    }

    public function sendEmailVerificationNotification(): void
    {
        app(AuthMailer::class)->sendEmailVerification($this);
    }

    /**
     * Staff permission check, e.g. "billing.invoices.manage". Non-staff never have permissions.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->is_staff && $this->is_active && $this->role !== null && $this->role->allows($permission);
    }
}
