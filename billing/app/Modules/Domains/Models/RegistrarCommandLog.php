<?php

namespace App\Modules\Domains\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every registrar call (request redacted, response truncated).
 *
 * @property int $id
 * @property int|null $domain_id
 * @property string $registrar_key
 * @property string $command
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property bool $success
 * @property string|null $error
 * @property int $duration_ms
 * @property int|null $staff_id
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['domain_id', 'registrar_key', 'command', 'request', 'response', 'success', 'error', 'duration_ms', 'staff_id'])]
class RegistrarCommandLog extends Model
{
    protected $table = 'registrar_command_log';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
            'success' => 'boolean',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
