<?php

namespace App\Modules\Migration\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded file and what became of it: uploaded → staging → mapping → checking → ready → applying → completed,
 * or failed / expired.
 *
 * @property int $id
 * @property int $migration_source_id
 * @property string $source_system csv (whmcs_api later)
 * @property string $entity the importer key the run writes ("client")
 * @property string $status
 * @property int $generation
 * @property string|null $file_key
 * @property string $file_name
 * @property string $file_sha256
 * @property int $file_bytes
 * @property string $delimiter
 * @property list<string>|null $header
 * @property array{columns?: list<string>, date_format?: string|null}|null $mapping
 * @property array<string, mixed>|null $options
 * @property array{groups?: array<string, int>, currencies?: array<string, int>, countries?: array<string, int>}|null $found_values
 * @property int $row_count
 * @property int $staged_count
 * @property int $checked_count
 * @property int $create_count
 * @property int $update_count
 * @property int $unchanged_count
 * @property int $skip_count
 * @property int $decision_count
 * @property int $error_count
 * @property int $warning_count
 * @property int $tax_exempt_count
 * @property string|null $review_digest the digest of the reviewed rows, which confirming must send back
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $failed_count
 * @property string|null $error
 * @property int|null $applied_by
 * @property CarbonImmutable|null $applied_at when staff confirmed the import (the import time of every row)
 * @property CarbonImmutable|null $undo_until
 * @property int|null $requested_by
 * @property CarbonImmutable|null $heartbeat_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $purged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read MigrationSource $source
 * @property-read User|null $requester
 * @property-read User|null $applier
 */
#[Fillable([
    'migration_source_id', 'source_system', 'entity', 'status', 'generation', 'file_key', 'file_name', 'file_sha256',
    'file_bytes', 'delimiter', 'header', 'mapping', 'options', 'found_values', 'row_count', 'staged_count',
    'checked_count', 'create_count', 'update_count', 'unchanged_count', 'skip_count', 'decision_count', 'error_count',
    'warning_count', 'tax_exempt_count', 'created_count', 'updated_count', 'skipped_count', 'failed_count', 'error', 'requested_by',
    'heartbeat_at', 'expires_at', 'purged_at', 'review_digest', 'applied_by', 'applied_at', 'undo_until',
])]
class MigrationRun extends Model
{
    public const UPLOADED = 'uploaded';

    public const STAGING = 'staging';

    public const MAPPING = 'mapping';

    public const CHECKING = 'checking';

    public const READY = 'ready';

    public const APPLYING = 'applying';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    /** Days after its last activity that an import stopped partway, and never continued, loses its row details. */
    public const STOPPED_PURGE_DAYS = 30;

    /** Minutes without progress after which a running step is reported as stalled. */
    public const STALLED_MINUTES = 10;

    protected function casts(): array
    {
        return [
            'header' => 'encrypted:array',
            'mapping' => 'array',
            'options' => 'array',
            'found_values' => 'encrypted:array',
            'heartbeat_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'undo_until' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MigrationSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MigrationSource::class, 'migration_source_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * @return HasMany<MigrationRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(MigrationRecord::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::UPLOADED, self::STAGING, self::CHECKING, self::APPLYING], true);
    }

    public function isStalled(): bool
    {
        return $this->isRunning() && ($this->heartbeat_at ?? $this->updated_at ?? CarbonImmutable::now())->lt(CarbonImmutable::now()->subMinutes(self::STALLED_MINUTES));
    }

    /**
     * The run's name on every page: the file name, never an id (pages show the upload date next to it).
     */
    public function label(): string
    {
        return $this->file_name;
    }

    /**
     * Takes over a failed or stalled run for a new attempt at $status, in one conditional update: of two clicks on
     * Continue, only one starts a job.
     */
    public function claimStopped(string $status): bool
    {
        $claimed = self::query()->whereKey($this->id)->where(fn (Builder $query) => $query->where('status', self::FAILED)
            ->orWhere(fn (Builder $query) => $query->whereIn('status', [self::UPLOADED, self::STAGING, self::CHECKING])
                ->where('heartbeat_at', '<', CarbonImmutable::now()->subMinutes(self::STALLED_MINUTES))))
            ->update(['status' => $status, 'error' => null, 'heartbeat_at' => CarbonImmutable::now()]);

        $this->refresh();

        return $claimed === 1;
    }
}
