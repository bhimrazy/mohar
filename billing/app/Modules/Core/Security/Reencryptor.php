<?php

namespace App\Modules\Core\Security;

use App\Modules\Core\Models\ReencryptionCheckpoint;
use App\Support\Security\EncryptedColumn;
use App\Support\Security\EncryptedFiles;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use stdClass;

/**
 * Moves stored encrypter payloads to the current APP_KEY (`security:reencrypt`). Values are handled as payloads,
 * whatever they hold (a cast's string or JSON, Fortify's serialized 2FA secret), so nothing is decoded or re-cast,
 * no model event fires and `updated_at` does not move.
 *
 * Per target, rows are read in key order (keyset paging on the primary key) one chunk at a time. A value that the
 * current key alone decrypts is left as it is. Any other value is decrypted with APP_PREVIOUS_KEYS, encrypted with the
 * current key, and written with a compare-and-set on the old stored value, so a write made meanwhile by the app (which
 * already uses the current key) wins and is counted as `changed`. The written value is read back and decrypted with the
 * current key alone. A value no key decrypts is left untouched and counted. A real run saves a checkpoint after each
 * chunk, under a fingerprint of the current key, and resumes there.
 */
class Reencryptor
{
    /** Ids of undecryptable rows kept per target for the console (never logged). */
    private const SAMPLE_IDS = 10;

    private readonly Encrypter $current;

    private readonly string $fingerprint;

    /** The cache lock a real run holds; its owner token marks the run's pending files. */
    public const RUN_LOCK = 'security:reencrypt';

    /** The owner token of this run's lock (null for a dry run). */
    private ?string $run = null;

    /** Whether the row being processed had a value that no key decrypts. */
    private bool $undecryptableRow = false;

    public function __construct(private readonly EncrypterContract $any)
    {
        $key = (string) config('app.key');
        $raw = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;

        $this->current = new Encrypter($raw, (string) config('app.cipher'));
        $this->fingerprint = hash_hmac('sha256', 'mohar.reencryption-checkpoint', $raw);
    }

    public function forRun(?string $owner): static
    {
        $this->run = $owner;

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public static function emptyCounts(): array
    {
        return ['values' => 0, 'current' => 0, 'old_key' => 0, 'reencrypted' => 0, 'undecryptable' => 0, 'changed' => 0, 'failed' => 0, 'missing' => 0];
    }

    /**
     * Process one target until it is done or $budget values have been looked at (null: no cap; the budget is shared
     * by the targets of one run and decremented here).
     *
     * @return array{counts: array<string, int>, complete: bool, undecryptable_ids: list<int|string>}
     */
    public function process(string $name, EncryptedColumn|EncryptedFiles $target, bool $dryRun, int $chunk, ?int &$budget, bool $restart = false): array
    {
        $checkpoint = $dryRun ? null : $this->checkpoint($name, $target, $restart);

        if ($checkpoint?->completed) {
            return ['counts' => $checkpoint->counts, 'complete' => true, 'undecryptable_ids' => []];
        }

        $counts = $checkpoint !== null ? [...self::emptyCounts(), ...$checkpoint->counts] : self::emptyCounts();
        $after = $checkpoint?->last_key;
        $ids = [];

        while ($budget === null || $budget > 0) {
            $size = $budget === null ? $chunk : min($chunk, $budget);
            $rows = $this->chunk($target, $after, $size);

            foreach ($rows as $row) {
                $before = $counts['values'];
                $this->undecryptableRow = false;
                $target instanceof EncryptedColumn
                    ? $this->column($target, $row, $dryRun, $counts)
                    : $this->files($name, $target, $row, $dryRun, $counts);

                if ($this->undecryptableRow && count($ids) < self::SAMPLE_IDS) {
                    $ids[] = $row->k;
                }

                if ($budget !== null) {
                    $budget -= max(1, $counts['values'] - $before);
                }

                $after = (string) $row->k;
            }

            $complete = $rows->count() < $size;

            if (! $dryRun) {
                $this->save($name, $after, $complete, $counts);
            }

            if ($complete) {
                return ['counts' => $counts, 'complete' => true, 'undecryptable_ids' => $ids];
            }
        }

        return ['counts' => $counts, 'complete' => false, 'undecryptable_ids' => $ids];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function column(EncryptedColumn $target, stdClass $row, bool $dryRun, array &$counts): void
    {
        $stored = (string) $row->v;
        $value = $target->json ? json_decode($stored, true) : $stored;

        if (! is_string($value) || ($target->prefix !== '' && ! str_starts_with($value, $target->prefix))) {
            return; // not an encrypted value (a plain setting)
        }

        $counts['values']++;
        $plaintext = $this->classify(substr($value, strlen($target->prefix)), $counts);

        if ($plaintext === null || $dryRun) {
            return;
        }

        $value = $target->prefix.$this->current->encrypt($plaintext, false);
        $new = $target->json ? (string) json_encode($value) : $value;

        if ($this->compareAndSet($target, $row->k, $stored, $new) === 0) {
            $counts['changed']++;

            return;
        }

        $readBack = $this->connection($target->connection)->table($target->table)->where($target->keyColumn, $row->k)->value($target->column);

        if ($readBack !== $new) {
            $counts['changed']++; // written again by the app right after us
        } elseif ($this->decryptsTo(substr($target->json ? (string) json_decode($new, true) : $new, strlen($target->prefix)), $plaintext)) {
            $counts['reencrypted']++;
        } else {
            $this->compareAndSet($target, $row->k, $new, $stored);
            $counts['failed']++;
        }
    }

    /**
     * A file on an old key is written under a new random name, recorded as pending in the checkpoint first, then the
     * row is switched to it under a lock (the parent row first, then this one, as the module's own writers lock), and
     * the old file is deleted once that commits. A run killed anywhere in between is settled by settle() on the next
     * run, which keeps the file the row points at and deletes the other.
     *
     * @param  array<string, int>  $counts
     */
    private function files(string $name, EncryptedFiles $target, stdClass $row, bool $dryRun, array &$counts): void
    {
        $disk = Storage::disk($target->disk);

        foreach ($target->pathColumns as $column) {
            $path = $row->{$column} ?? null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            $counts['values']++;

            if (! $disk->exists($path)) {
                $counts['missing']++;

                continue;
            }

            $plaintext = $this->classify((string) $disk->get($path), $counts);

            if ($plaintext === null || $dryRun) {
                continue;
            }

            $newPath = (dirname($path) === '.' ? '' : dirname($path).'/').Str::uuid()->toString();
            $this->pending($name, ['key' => $row->k, 'column' => $column, 'old' => $path, 'new' => $newPath, 'run' => $this->run]);
            $disk->put($newPath, $this->current->encrypt($plaintext, false));

            if (! $this->decryptsTo((string) $disk->get($newPath), $plaintext)) {
                $disk->delete($newPath);
                $this->pending($name, null);
                $counts['failed']++;

                continue;
            }

            $switched = $this->underLock($target, $row->k, function (Builder $locked) use ($target, $row, $column, $path, $newPath, $disk): bool {
                // Deleted, given another file meanwhile, or the new file was removed by an overlapping run's settle().
                if ($locked->value($column) !== $path || ! $disk->exists($newPath)) {
                    return false;
                }

                $this->connection($target->connection)->table($target->table)->where($target->keyColumn, $row->k)->update([$column => $newPath]);

                return true;
            });

            // After the commit: a crash before this line is settled from the pending record.
            $disk->delete($switched ? $path : $newPath);

            $this->pending($name, null);
            $counts[$switched ? 'reencrypted' : 'changed']++;
        }
    }

    /**
     * Finish what a killed run left: under the row's lock, delete whichever of the two files the row does not point at
     * (both when the row is gone). A pending file of a run that still holds the lock is left alone: that run is alive
     * (the lock expired for us, or the lock store is not shared) and finishes it. Returns whether it settled.
     *
     * @param  array{key: int|string, column: string, old: string, new: string, run?: string|null}  $pending
     */
    private function settle(EncryptedFiles $target, array $pending): bool
    {
        $run = $pending['run'] ?? null;

        if (is_string($run) && $run !== $this->run) {
            $lock = Cache::restoreLock(self::RUN_LOCK, $run);

            // Every lock-capable store's lock can say whether that owner still holds it; treat anything else as live.
            if (! $lock instanceof Lock || $lock->isOwnedByCurrentProcess()) {
                return false;
            }
        }

        $disk = Storage::disk($target->disk);

        $this->underLock($target, $pending['key'], function (Builder $locked) use ($pending, $disk): void {
            $current = $locked->value($pending['column']);

            foreach ([$pending['old'], $pending['new']] as $path) {
                if ($path !== $current) {
                    $disk->delete($path);
                }
            }
        });

        return true;
    }

    /**
     * Run $callback in a transaction holding the row's lock (and its parent's first), given the locked row's query.
     *
     * @template T
     *
     * @param  Closure(Builder): T  $callback
     * @return T
     */
    private function underLock(EncryptedFiles $target, int|string $key, Closure $callback): mixed
    {
        $connection = $this->connection($target->connection);

        return $connection->transaction(function () use ($connection, $target, $key, $callback): mixed {
            if ($target->lockFirst !== null) {
                [$parentTable, $parentColumn] = $target->lockFirst;
                $parent = $connection->table($target->table)->where($target->keyColumn, $key)->value($parentColumn);

                if ($parent !== null) {
                    $connection->table($parentTable)->where('id', $parent)->lockForUpdate()->first(['id']);
                }
            }

            return $callback($connection->table($target->table)->where($target->keyColumn, $key)->lockForUpdate());
        });
    }

    /**
     * Record (or clear) the file being re-encrypted. Counts and position stay as the last finished chunk left them;
     * a target without a checkpoint yet resumes from its start.
     *
     * @param  array{key: int|string, column: string, old: string, new: string, run: string|null}|null  $pending
     */
    private function pending(string $name, ?array $pending): void
    {
        $checkpoint = ReencryptionCheckpoint::query()->firstOrNew(['target' => $name], ['key_fingerprint' => $this->fingerprint, 'counts' => self::emptyCounts()]);
        $checkpoint->pending = $pending;
        $checkpoint->save();
    }

    /**
     * The plaintext when the payload needs re-encryption, null when it is already on the current key or no key
     * decrypts it.
     *
     * @param  array<string, int>  $counts
     */
    private function classify(string $payload, array &$counts): ?string
    {
        try {
            $this->current->decrypt($payload, false);
            $counts['current']++;

            return null;
        } catch (DecryptException) {
        }

        try {
            $plaintext = (string) $this->any->decrypt($payload, false);
            $counts['old_key']++;

            return $plaintext;
        } catch (DecryptException) {
            $counts['undecryptable']++;
            $this->undecryptableRow = true;

            return null;
        }
    }

    private function decryptsTo(string $payload, string $plaintext): bool
    {
        try {
            return hash_equals($plaintext, (string) $this->current->decrypt($payload, false));
        } catch (DecryptException) {
            return false;
        }
    }

    private function compareAndSet(EncryptedColumn $target, int|string $key, string $old, string $new): int
    {
        $connection = $this->connection($target->connection);
        $row = fn () => $connection->table($target->table)->where($target->keyColumn, $key);

        if (! $target->json) {
            return $row()->where($target->column, $old)->update([$target->column => $new]);
        }

        // PostgreSQL has no equality operator for json: lock the row and compare the stored text (json keeps it verbatim).
        return $connection->transaction(fn (): int => $row()->lockForUpdate()->value($target->column) === $old
            ? $row()->update([$target->column => $new])
            : 0);
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function chunk(EncryptedColumn|EncryptedFiles $target, ?string $after, int $size): Collection
    {
        $columns = $target instanceof EncryptedColumn ? [$target->column.' as v'] : $target->pathColumns;
        $intKey = $target instanceof EncryptedFiles || $target->intKey;

        // Keyset paging on the primary key (its index); the column filter only skips rows.
        return $this->connection($target->connection)->table($target->table)
            ->select([$target->keyColumn.' as k', ...$columns])
            ->where(function ($query) use ($target): void {
                foreach ($target instanceof EncryptedColumn ? [$target->column] : $target->pathColumns as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->when($after !== null, fn ($query) => $query->where($target->keyColumn, '>', $intKey ? (int) $after : $after))
            ->orderBy($target->keyColumn)
            ->limit($size)
            ->get();
    }

    private function connection(?string $name): Connection
    {
        return DB::connection($name);
    }

    private function checkpoint(string $name, EncryptedColumn|EncryptedFiles $target, bool $restart): ?ReencryptionCheckpoint
    {
        $checkpoint = ReencryptionCheckpoint::query()->where('target', $name)->first();

        if ($checkpoint?->pending !== null && $target instanceof EncryptedFiles && $this->settle($target, $checkpoint->pending)) {
            $checkpoint->update(['pending' => null]);
        }

        if ($checkpoint !== null && ($restart || ! hash_equals($checkpoint->key_fingerprint, $this->fingerprint))) {
            $checkpoint->delete(); // --restart, or written under another key: start this target again

            return null;
        }

        return $checkpoint;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function save(string $name, ?string $after, bool $complete, array $counts): void
    {
        ReencryptionCheckpoint::query()->updateOrCreate(
            ['target' => $name],
            ['key_fingerprint' => $this->fingerprint, 'last_key' => $after, 'completed' => $complete, 'counts' => $counts, 'pending' => null],
        );
    }
}
