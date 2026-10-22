<?php

namespace App\Modules\Migration\Models;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Core\Support\InstallSecretUnavailable;
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
    public const EMAIL_HASH_SECRET = 'migration_email_hash';

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
     * The keyed hash of an email, for duplicate checks, exact search and the email-keyed id map, without decrypting
     * rows. The key is an install secret (InstallSecrets, defined by emailHashKey()), so the hash means nothing outside
     * this install and stays the same when APP_KEY is rotated.
     */
    public static function emailHash(string $email): string
    {
        return self::hashWith(app(InstallSecrets::class)->get(self::EMAIL_HASH_SECRET), $email);
    }

    /**
     * The initial value of the email-hash secret: the key earlier versions derived from APP_KEY, from whichever
     * configured key the stored hashes were made with, so they keep matching; the current key's when nothing is
     * stored yet. A key is accepted only when it reproduces a stored hash from an email: a staged record's own
     * (until the retention purge clears it) or the email of the client an `email:<hash>` id-map row or an applied
     * record points to (which survive the purge). When hashes exist, several keys are configured and none can be
     * checked, it refuses rather than guess, because a wrong guess silently breaks re-imports.
     *
     * @param  list<string>  $keys  the configured keys, current first
     *
     * @throws InstallSecretUnavailable
     */
    public static function emailHashKey(array $keys): string
    {
        $derive = fn (string $key): string => hash_hmac('sha256', 'migration.email_hash', $key);

        if (! self::query()->whereNotNull('email_hash')->exists() && ! MigrationIdMap::query()->where('source_id', 'like', 'email:%')->exists()) {
            return $derive($keys[0] ?? '');
        }

        foreach (self::storedEmails() as [$hash, $email]) {
            foreach ($keys as $key) {
                if (hash_equals($hash, self::hashWith($derive($key), $email))) {
                    return $derive($key);
                }
            }
        }

        if (count($keys) <= 1) {
            return $derive($keys[0] ?? '');
        }

        throw new InstallSecretUnavailable('Stored import email hashes match none of the configured keys (APP_KEY, APP_PREVIOUS_KEYS), so the email-hash secret cannot be set. Restore the key in use before the rotation as APP_KEY, run `php artisan migrate`, then rotate in a later restart.');
    }

    /**
     * Pairs of a stored email hash and an email it may have been made from, a few from each source.
     *
     * @return iterable<array{0: string, 1: string}>
     */
    private static function storedEmails(): iterable
    {
        $limit = 20;

        foreach (self::query()->whereNotNull('email_hash')->whereNotNull('normalized')->orderBy('id')->limit($limit)->get(['id', 'email_hash', 'normalized']) as $record) {
            $email = $record->normalized['client']['email'] ?? null;

            if (is_string($email)) {
                yield [(string) $record->email_hash, $email];
            }
        }

        $mapped = MigrationIdMap::query()->join('clients', 'clients.id', '=', 'migration_id_map.target_id')
            ->where('migration_id_map.entity', 'client')->where('migration_id_map.source_id', 'like', 'email:%')
            ->orderBy('migration_id_map.id')->limit($limit)->get(['migration_id_map.source_id', 'clients.email']);

        foreach ($mapped as $row) {
            yield [substr((string) $row->source_id, strlen('email:')), (string) $row->getAttribute('email')];
        }

        $applied = self::query()->join('clients', 'clients.id', '=', 'migration_records.target_id')
            ->whereNotNull('migration_records.email_hash')->orderBy('migration_records.id')->limit($limit)
            ->get(['migration_records.email_hash', 'clients.email']);

        foreach ($applied as $row) {
            yield [(string) $row->email_hash, (string) $row->getAttribute('email')];
        }
    }

    private static function hashWith(string $key, string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), $key);
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
