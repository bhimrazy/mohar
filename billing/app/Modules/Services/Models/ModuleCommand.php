<?php

namespace App\Modules\Services\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt of a driver command (module log). Request/response are stored redacted.
 *
 * @property int $id
 * @property int|null $service_id
 * @property int|null $service_addon_id
 * @property int|null $server_id
 * @property string $driver
 * @property string $command
 * @property string $initiator
 * @property int $attempt
 * @property bool $success
 * @property string|null $error
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property int $duration_ms
 * @property CarbonImmutable|null $created_at
 * @property-read Service|null $service
 * @property-read Server|null $server
 */
#[Fillable([
    'service_id', 'service_addon_id', 'server_id', 'driver', 'command', 'initiator', 'attempt', 'success', 'error',
    'request', 'response', 'duration_ms', 'created_at',
])]
class ModuleCommand extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'request' => 'array',
            'response' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
