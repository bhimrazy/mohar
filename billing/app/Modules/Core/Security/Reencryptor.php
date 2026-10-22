<?php

namespace App\Modules\Core\Security;

use App\Modules\Core\Models\ReencryptionCheckpoint;
use App\Support\Security\EncryptedColumn;
use App\Support\Security\EncryptedFiles;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
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

    /** Whether the row being processed had a value that no key decrypts. */
    private bool $undecryptableRow = false;

    public function __construct(private readonly EncrypterContract $any)
    {
        $key = (string) config('app.key');
        $raw = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;

        $this->current = new Encrypter($raw, (string) config('app.cipher'));
        $this->fingerprint = hash_hmac('sha256', 'mohar.reencryption-checkpoint', $raw);
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
    public function process(string $name, EncryptedColumn|EncryptedFiles $target, bool $dryRun, int $chunk, ?int &$budget): array
    {
        $checkpoint = $dryRun ? null : $this->checkpoint($name);

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
                    : $this->files($target, $row, $dryRun, $counts);

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
     * @param  array<string, int>  $counts
     */
    private function files(EncryptedFiles $target, stdClass $row, bool $dryRun, array &$counts): void
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
            $disk->put($newPath, $this->current->encrypt($plaintext, false));

            if (! $this->decryptsTo((string) $disk->get($newPath), $plaintext)) {
                $disk->delete($newPath);
                $counts['failed']++;

                continue;
            }

            $switched = $this->connection($target->connection)->table($target->table)
                ->where($target->keyColumn, $row->k)->where($column, $path)->update([$column => $newPath]);

            if ($switched === 0) {
                $disk->delete($newPath); // the row was deleted or got another file meanwhile
                $counts['changed']++;

                continue;
            }

            $disk->delete($path);
            $counts['reencrypted']++;
        }
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
        $query = $connection->table($target->table)->where($target->keyColumn, $key);

        // PostgreSQL has no equality operator for json; compare the stored text, which json keeps verbatim.
        $target->json
            ? $query->whereRaw('CAST('.$connection->getQueryGrammar()->wrap($target->column).' AS TEXT) = ?', [$old])
            : $query->where($target->column, $old);

        return $query->update([$target->column => $new]);
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

    private function checkpoint(string $name): ?ReencryptionCheckpoint
    {
        $checkpoint = ReencryptionCheckpoint::query()->where('target', $name)->first();

        if ($checkpoint !== null && ! hash_equals($checkpoint->key_fingerprint, $this->fingerprint)) {
            $checkpoint->delete(); // written under another key: start this target again

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
            ['key_fingerprint' => $this->fingerprint, 'last_key' => $after, 'completed' => $complete, 'counts' => $counts],
        );
    }
}
