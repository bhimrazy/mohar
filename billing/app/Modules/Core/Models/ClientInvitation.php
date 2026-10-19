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
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $invited_by
 * @property-read Client $client
 * @property-read User $user
 */
#[Fillable(['client_id', 'user_id', 'token_hash', 'expires_at', 'accepted_at', 'invited_by'])]
#[Hidden(['token_hash'])]
class ClientInvitation extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
    }

    public static function hashToken(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
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
     * valid, expired or used — what the accept page shows.
     */
    public function state(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'used',
            $this->isExpired() => 'expired',
            default => 'valid',
        };
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
