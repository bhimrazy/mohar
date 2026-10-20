<?php

namespace App\Modules\Services\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One fetch of a server's accounts for Import accounts (cPanel spec §10), kept for HOURS hours.
 *
 * @property int $id
 * @property int $server_id
 * @property int|null $requested_by
 * @property string $status fetching|matching|importing|ready|failed
 * @property list<array<string, mixed>>|null $accounts RemoteAccount fields plus how each matched at fetch time
 *                                                     (AccountImportMatcher::classify()), in the panel's order (encrypted)
 * @property int $account_count
 * @property int $proposed_count
 * @property int $unmatched_count
 * @property int $reseller_count
 * @property int $linked_count
 * @property list<int>|null $missing ids of live services whose account the server did not report
 * @property string|null $connection_user the panel user the fetch signed in as
 * @property array<string, array{include?: bool, client_id?: int, product_id?: int, cycle?: string, next_due_date?: string}>|null $selections staff choices by username
 * @property array{digest: string, rows: list<array{username: string, client_id: int, product_id: int, cycle: string, next_due_date: string, anchor_day: int, amount: int, currency: string}>, staff_id: int|null}|null $reviewed what the review page showed
 * @property int $imported_count accounts the last import created
 * @property int $not_imported_count reviewed accounts the last import left out, or did not reach when it stopped
 * @property string|null $stop_reason why the last import stopped early
 * @property-read list<array{username: string, imported: bool, message: string, service_id: int|null}> $report the last
 *                                                                                                   import's lines
 * @property string|null $error our message, redacted
 * @property CarbonImmutable|null $fetched_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $server
 * @property-read User|null $requester
 */
#[Hidden(['accounts'])]
#[Fillable(['server_id', 'requested_by', 'status', 'accounts', 'account_count', 'proposed_count', 'unmatched_count', 'reseller_count', 'linked_count', 'missing', 'connection_user', 'selections', 'reviewed', 'imported_count', 'not_imported_count', 'stop_reason', 'error', 'fetched_at', 'expires_at'])]
class ServerAccountImport extends Model
{
    public const FETCHING = 'fetching';

    public const READY = 'ready';

    /** "Match again" is re-matching a ready list on the queue. */
    public const MATCHING = 'matching';

    /** The reviewed accounts are being imported on the queue. */
    public const IMPORTING = 'importing';

    public const FAILED = 'failed';

    /** How long a fetched list is kept for review. */
    public const HOURS = 24;

    /** A fetch that has not finished after this long is taken as dead, and a new one may start. */
    public const STALE_MINUTES = 10;

    protected function casts(): array
    {
        return [
            'accounts' => 'encrypted:array',
            'account_count' => 'integer',
            'proposed_count' => 'integer',
            'unmatched_count' => 'integer',
            'reseller_count' => 'integer',
            'linked_count' => 'integer',
            'missing' => 'array',
            'selections' => 'array',
            'reviewed' => 'array',
            'imported_count' => 'integer',
            'not_imported_count' => 'integer',
            'fetched_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The last import's lines, in order (server_account_import_report_lines); read only when asked for.
     *
     * @return Attribute<list<array{username: string, imported: bool, message: string, service_id: int|null}>, never>
     */
    protected function report(): Attribute
    {
        return Attribute::get(fn (): array => $this->reportLines());
    }

    /**
     * @return list<array{username: string, imported: bool, message: string, service_id: int|null}>
     */
    public function reportLines(?int $limit = null): array
    {
        return array_values(DB::table('server_account_import_report_lines')->where('server_account_import_id', $this->id)->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit((int) $limit))
            ->get(['username', 'imported', 'message', 'service_id'])
            ->map(fn (object $line): array => ['username' => (string) $line->username, 'imported' => (bool) $line->imported, 'message' => (string) $line->message, 'service_id' => $line->service_id === null ? null : (int) $line->service_id])
            ->all());
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::FETCHING, self::MATCHING, self::IMPORTING], true)
            && $this->updated_at !== null && $this->updated_at->greaterThan(now()->subMinutes(self::STALE_MINUTES));
    }
}
