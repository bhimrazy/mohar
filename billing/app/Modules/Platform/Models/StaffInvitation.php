<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use App\Modules\Core\Models\Role;
use App\Modules\Platform\Database\Factories\StaffInvitationFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property int $role_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $invited_by
 * @property CarbonImmutable|null $created_at
 * @property-read Role $role
 * @property-read User|null $inviter
 */
#[UseFactory(StaffInvitationFactory::class)]
#[Fillable(['email', 'name', 'role_id', 'token_hash', 'expires_at', 'accepted_at', 'invited_by'])]
#[Hidden(['token_hash'])]
class StaffInvitation extends Model
{
    /** @use HasFactory<StaffInvitationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
