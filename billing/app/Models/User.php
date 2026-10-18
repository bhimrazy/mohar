<?php

namespace App\Models;

use App\Events\Core\EmailVerificationRequested;
use App\Events\Core\PasswordResetRequested;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Role;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
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
     * Changing the password records when and clears a pending forced change (admin-set temporary password).
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
                $user->must_change_password = false;
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
     * Password reset links go out as an event when something handles it (Notifications: editable, logged,
     * branded); without a listener (e.g. MODULES= without Notifications) Laravel's default notification is sent.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if (! Event::hasListeners(PasswordResetRequested::class)) {
            parent::sendPasswordResetNotification($token);

            return;
        }

        $url = ResetPassword::$createUrlCallback !== null
            ? (string) call_user_func(ResetPassword::$createUrlCallback, $this, $token)
            : url(route('password.reset', ['token' => $token, 'email' => $this->getEmailForPasswordReset()], false));
        $broker = (string) config('auth.defaults.passwords', 'users');
        $expiresAt = now()->addMinutes((int) config("auth.passwords.{$broker}.expire", 60));

        PasswordResetRequested::dispatch($this->id, $url, $expiresAt->format('Y-m-d H:i'), $token);
    }

    /**
     * Same pattern as password resets for the signed email-verification link.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (! Event::hasListeners(EmailVerificationRequested::class)) {
            parent::sendEmailVerificationNotification();

            return;
        }

        EmailVerificationRequested::dispatch($this->id, VerifyEmail::$createUrlCallback !== null
            ? (string) call_user_func(VerifyEmail::$createUrlCallback, $this)
            : URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                ['id' => $this->getKey(), 'hash' => sha1($this->getEmailForVerification())],
            ));
    }

    /**
     * Staff permission check, e.g. "billing.invoices.manage". Non-staff never have permissions.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->is_staff && $this->is_active && $this->role !== null && $this->role->allows($permission);
    }
}
