<?php

namespace App\Modules\Migration\Models;

use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file row in staging. `raw` holds the cells by column position (password and card columns are never stored);
 * `normalized` is what the row becomes under the current mapping.
 *
 * @property int $id
 * @property int $migration_run_id
 * @property int $row_number
 * @property array<int, string>|null $raw
 * @property array<string, mixed>|null $normalized
 * @property string|null $email_hash
 * @property string|null $source_id
 * @property string|null $checksum
 * @property string|null $status ok|warning|error
 * @property string|null $decision create|update|unchanged|skip|needs_decision
 * @property int|null $match_client_id
 * @property list<array{severity: string, code: string, message: string, field?: string|null, row?: int|null}>|null $problems
 * @property list<string>|null $changes
 * @property bool $tax_exempt whether the row sets tax exemption
 * @property bool|null $link_login staff chose to link (true) or not link (false) an existing login
 * @property string|null $review_hash this row's part of the run's review digest
 * @property string|null $outcome created|updated|unchanged|skipped|failed, once applied
 * @property int|null $target_id the client the row created or updated
 * @property array<string, array<string, mixed>>|null $before per entity (client, credit_balance), the changed fields'
 *                                                            previous values each importer read under its lock (for undo)
 * @property array<string, array{action: string, target_id: int|null, changes: list<string>}>|null $entities per entity,
 *                                                                                                           what its importer did
 * @property CarbonImmutable|null $applied_at
 * @property-read MigrationRun $run
 * @property-read Client|null $matchedClient
 */
#[Fillable(['migration_run_id', 'row_number', 'raw'])]
class MigrationRecord extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'raw' => 'encrypted:array',
            'normalized' => 'encrypted:array',
            'problems' => 'array',
            'changes' => 'array',
            'before' => 'encrypted:array',
            'entities' => 'array',
            'tax_exempt' => 'boolean',
            'link_login' => 'boolean',
            'applied_at' => 'immutable_datetime',
        ];
    }

    /**
     * The keyed hash of an email, for duplicate checks and exact search without decrypting rows. The key is derived
     * from APP_KEY, so the hash means nothing outside this install.
     */
    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), hash_hmac('sha256', 'migration.email_hash', (string) config('app.key')));
    }

    /**
     * @return BelongsTo<MigrationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MigrationRun::class, 'migration_run_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function matchedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'match_client_id');
    }
}
