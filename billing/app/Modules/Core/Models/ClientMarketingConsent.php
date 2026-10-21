<?php

namespace App\Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a client's marketing consent history (append-only; the latest row is the current state).
 *
 * @property int $id
 * @property int $client_id
 * @property string $state granted|withdrawn|unknown
 * @property string $channel
 * @property string $source signup|client|staff|import_csv|import_whmcs|import_undo
 * @property string|null $evidence what the source said, in our words
 * @property CarbonImmutable|null $consented_at when the person consented, if known
 * @property CarbonImmutable $recorded_at
 * @property int|null $recorded_by
 * @property int|null $migration_run_id
 */
#[Fillable(['client_id', 'state', 'channel', 'source', 'evidence', 'consented_at', 'recorded_at', 'recorded_by', 'migration_run_id'])]
class ClientMarketingConsent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['consented_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }

    /**
     * The client's current state, or null when nothing was ever recorded.
     */
    public static function currentState(int $clientId): ?string
    {
        return self::query()->where('client_id', $clientId)->orderByDesc('id')->value('state');
    }
}
