<?php

namespace App\Modules\Services\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fetch of a server's accounts for Import accounts (cPanel spec §10), kept for HOURS hours.
 *
 * @property int $id
 * @property int $server_id
 * @property int|null $requested_by
 * @property string $status fetching|ready|failed
 * @property list<array<string, mixed>>|null $accounts RemoteAccount fields plus how each matched at fetch time
 *                                                     (AccountImportMatcher::classify()), in the panel's order (encrypted)
 * @property int $account_count
 * @property int $proposed_count
 * @property int $unmatched_count
 * @property int $reseller_count
 * @property int $linked_count
 * @property list<int>|null $missing ids of live services whose account the server did not report
 * @property array<string, array{include?: bool, client_id?: int, product_id?: int, cycle?: string, next_due_date?: string}>|null $selections staff choices by username
 * @property string|null $error our message, redacted
 * @property CarbonImmutable|null $fetched_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $server
 * @property-read User|null $requester
 */
#[Hidden(['accounts'])]
#[Fillable(['server_id', 'requested_by', 'status', 'accounts', 'account_count', 'proposed_count', 'unmatched_count', 'reseller_count', 'linked_count', 'missing', 'selections', 'error', 'fetched_at', 'expires_at'])]
class ServerAccountImport extends Model
{
    public const FETCHING = 'fetching';

    public const READY = 'ready';

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

    public function isRunning(): bool
    {
        return $this->status === self::FETCHING && $this->created_at !== null && $this->created_at->greaterThan(now()->subMinutes(self::STALE_MINUTES));
    }
}
