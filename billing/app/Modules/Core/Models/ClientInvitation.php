<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation for a new client login: the token sets the login's password once, until it expires.
 *
 * @property int $id
 * @property int $client_id
 * @property int $user_id
 * @property string $token_hash
 * @property string $email
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $invited_by
 * @property string|null $password_fingerprint
 * @property-read Client $client
 * @property-read User $user
 */
#[Fillable(['client_id', 'user_id', 'token_hash', 'email', 'sent_at', 'expires_at', 'accepted_at', 'invited_by', 'password_fingerprint'])]
#[Hidden(['token_hash', 'password_fingerprint'])]
class ClientInvitation extends Model
{
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
    }

    public static function hashToken(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Stands for the login's password hash at sending time, so any later write of the password voids the link, with
     * or without model events.
     */
    public static function fingerprint(#[\SensitiveParameter] ?string $passwordHash): string
    {
        return hash('sha256', (string) $passwordHash);
    }

    /**
     * A client login nobody has used: active, never signed in, no two-factor authentication, and its password never
     * changed since the login was created (the random one it was created with, which nobody knows). Only such a login
     * may be sent an invitation. The client import lists logins with the same rule in SQL (Migration's
     * ImportInvitations::candidates()); a test checks both agree. Needs is_staff, is_active, last_login_at, the
     * two-factor columns, password_changed_at and created_at.
     */
    public static function neverUsed(User $user): bool
    {
        return ! $user->is_staff && $user->is_active && $user->last_login_at === null
            && $user->two_factor_secret === null && $user->two_factor_confirmed_at === null
            && ($user->password_changed_at === null || $user->created_at === null || $user->password_changed_at->lessThanOrEqualTo($user->created_at));
    }

    /**
     * Whether staff may send a first invitation to a login that has none: never used, and its email not verified
     * (a login staff created verifies it by accepting). A verified login may have been set up by its holder, so its
     * password is never replaced by an invitation; it gets a password reset.
     */
    public static function invitableWithoutOne(User $user): bool
    {
        return $user->email_verified_at === null && self::neverUsed($user);
    }

    public static function findByToken(#[\SensitiveParameter] string $token): ?self
    {
        return self::query()->with(['client', 'user'])->where('token_hash', self::hashToken($token))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * valid, expired, used or void — what the accept page shows. A link is void once its login is no longer the
     * never-used login it was mailed to: another email address, a password set some other way, two-factor
     * authentication, a sign-in, or access to this account removed.
     */
    public function state(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'used',
            $this->isVoid() => 'void',
            $this->isExpired() => 'expired',
            default => 'valid',
        };
    }

    public function isVoid(): bool
    {
        return $this->loginChanged($this->user) || ! $this->client->users()->whereKey($this->user_id)->exists();
    }

    /**
     * The login checks of isVoid() without the access check, for a list that already holds the account's logins.
     * The login needs its email, password, password_changed_at, two-factor and last_login_at columns.
     */
    public function loginChanged(User $user): bool
    {
        return mb_strtolower($user->email) !== mb_strtolower($this->email)
            || ($user->password_changed_at !== null && $user->password_changed_at->greaterThan($this->created_at ?? $this->sent_at))
            || ($this->password_fingerprint !== null && ! hash_equals($this->password_fingerprint, self::fingerprint($user->password)))
            || $user->two_factor_secret !== null || $user->two_factor_confirmed_at !== null
            || $user->last_login_at !== null;
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
