<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record of a staff member signing in to the client area as a client's user.
 *
 * @property int $id
 * @property int $staff_id
 * @property int $client_id
 * @property int $user_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $end_reason
 * @property string|null $ip
 * @property-read User $staff
 * @property-read User $user
 * @property-read Client $client
 */
#[Fillable(['staff_id', 'client_id', 'user_id', 'started_at', 'ended_at', 'end_reason', 'ip'])]
class ImpersonationSession extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
