<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $client_id
 * @property string $description
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $actor_type
 * @property int|null $impersonator_id
 * @property string|null $event
 * @property string|null $user_agent
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['user_id', 'client_id', 'description', 'subject_type', 'subject_id', 'properties', 'ip_address', 'actor_type', 'impersonator_id', 'event', 'user_agent'])]
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
