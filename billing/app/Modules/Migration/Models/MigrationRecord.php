<?php

namespace App\Modules\Migration\Models;

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
 * @property CarbonImmutable|null $applied_at
 * @property-read MigrationRun $run
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
}
