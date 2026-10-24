<?php

namespace App\Modules\Services\Support;

use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Core\Support\Like;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerAccountImportAccount;
use Generator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * The accounts of a fetched list (cPanel spec §10), one row each in server_account_import_accounts. Callers filter and
 * count on the plain columns (username, domain, category, client_id, a keyed hash of the contact email) and decrypt
 * only the rows they show or act on, so a page view or an import chunk costs what it touches, not the whole list.
 * A row's `payload` is the whole account row, encrypted; only rows() without usernames reads a list whole.
 */
class AccountList
{
    /** The install secret keying the email hash: it stays the same when APP_KEY is rotated. */
    public const EMAIL_HASH_SECRET = 'services.import_email_hash';

    /** An account staff left out of the work list; remembered per server in server_import_ignores. */
    public const IGNORED = 'ignored';

    private const TABLE = 'server_account_import_accounts';

    /** The columns a read needs: the plain category wins over the payload's (shape()). */
    private const READ = ['username', 'payload', 'category', 'client_id'];

    private const INSERT_CHUNK = 200;

    private const LOOKUP_CHUNK = 500;

    /**
     * Replace the import's accounts with $rows, in order.
     *
     * @param  list<array<string, mixed>>  $rows  RemoteAccount fields, plus category and client_id once classified
     */
    public function replace(ServerAccountImport $import, array $rows): void
    {
        DB::table(self::TABLE)->where('server_account_import_id', $import->id)->delete();

        foreach (array_chunk($rows, self::INSERT_CHUNK, preserve_keys: true) as $chunk) {
            DB::table(self::TABLE)->insertOrIgnore(array_map(fn (array $row, int $position): array => [
                'server_account_import_id' => $import->id,
                'position' => $position,
                'username' => mb_substr((string) $row['username'], 0, 255),
                'domain' => isset($row['domain']) ? mb_substr(mb_strtolower((string) $row['domain']), 0, 255) : null,
                'email_hash' => isset($row['email']) ? self::emailHash((string) $row['email']) : null,
                'category' => $row['category'] ?? AccountImportMatcher::UNMATCHED,
                'client_id' => $row['client_id'] ?? null,
                'payload' => Crypt::encryptString(json_encode($row, JSON_THROW_ON_ERROR)),
            ], $chunk, array_keys($chunk)));
        }
    }

    /**
     * The accounts by username, in the panel's order: all of them, or only those named.
     *
     * @param  list<string>|null  $usernames
     * @return array<string, array<string, mixed>>
     */
    public function rows(ServerAccountImport $import, ?array $usernames = null): array
    {
        if ($usernames === null) {
            // A cursor, so PHP holds one record beside the decrypted rows (a PostgreSQL driver may still buffer the result).
            $rows = [];

            foreach ($this->query($import)->orderBy('position')->select(self::READ)->cursor() as $record) {
                $rows[(string) $record->username] = self::shape($record);
            }

            return $rows;
        }

        $found = [];

        foreach (array_chunk(array_values(array_unique($usernames)), self::LOOKUP_CHUNK) as $chunk) {
            array_push($found, ...$this->query($import)->whereIn('username', $chunk)->orderBy('position')->get(self::READ)->all());
        }

        return $this->decode($found);
    }

    /**
     * One page of the list as the Import accounts page shows it: the tab's category and the search term (username,
     * domain, or the exact contact email) are matched on the plain columns, then only that page is decrypted.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(ServerAccountImport $import, string $tab, string $search, int $page, int $perPage): array
    {
        $filtered = fn () => $this->query($import)
            ->when($tab !== 'all', fn ($query) => $query->where('category', $tab))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                Like::where($query, 'username', Like::contains($search));
                Like::where($query, 'domain', Like::contains($search), 'or');

                if (str_contains($search, '@')) {
                    $query->orWhere('email_hash', self::emailHash($search));
                }
            }));

        return [
            'rows' => array_values($this->decode($filtered()->orderBy('position')->offset(($page - 1) * $perPage)->limit($perPage)->get(self::READ)->values()->all())),
            'total' => $filtered()->count(),
        ];
    }

    /**
     * How many accounts each category holds.
     *
     * @return array<string, int>
     */
    public function counts(ServerAccountImport $import): array
    {
        return $this->query($import)->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category')
            ->map(fn (mixed $total): int => (int) $total)->all();
    }

    /**
     * The matched client of every account in a category, by username (plain columns only).
     *
     * @return array<string, int|null>
     */
    public function clientsIn(ServerAccountImport $import, string $category): array
    {
        return $this->query($import)->where('category', $category)->orderBy('position')->pluck('client_id', 'username')
            ->map(fn (mixed $id): ?int => $id === null ? null : (int) $id)->all();
    }

    /**
     * Which of these usernames are in the list.
     *
     * @param  list<string>  $usernames
     * @return list<string>
     */
    public function existing(ServerAccountImport $import, array $usernames): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($usernames)), self::LOOKUP_CHUNK) as $chunk) {
            array_push($found, ...$this->query($import)->whereIn('username', $chunk)->pluck('username')->all());
        }

        return $found;
    }

    /**
     * Mark accounts as linked to the services created for them: one UPDATE of the plain columns per chunk, no
     * decryption. shape() shows a linked row from them, whatever its payload still says.
     *
     * @param  array<string, int>  $services  service id by username
     */
    public function link(ServerAccountImport $import, array $services): void
    {
        foreach (array_chunk(array_keys($services), self::LOOKUP_CHUNK) as $chunk) {
            $this->query($import)->whereIn('username', $chunk)->update(['category' => AccountImportMatcher::LINKED, 'client_id' => null]);
        }
    }

    /**
     * Ignore or restore accounts (never linked ones). A restored account returns to the category its payload holds.
     *
     * @param  list<string>  $usernames
     */
    public function setIgnored(ServerAccountImport $import, array $usernames, bool $ignored): void
    {
        foreach (array_chunk(array_values(array_unique($usernames)), self::LOOKUP_CHUNK) as $chunk) {
            if ($ignored) {
                $this->query($import)->whereIn('username', $chunk)->whereNotIn('category', [AccountImportMatcher::LINKED, self::IGNORED])->update(['category' => self::IGNORED]);

                continue;
            }

            $restore = [];

            foreach ($this->query($import)->whereIn('username', $chunk)->where('category', self::IGNORED)->get(['username', 'payload']) as $record) {
                $restore[(string) ($record->payload['category'] ?? AccountImportMatcher::UNMATCHED)][] = (string) $record->username;
            }

            foreach ($restore as $category => $names) {
                $this->query($import)->whereIn('username', $names)->update(['category' => $category]);
            }
        }
    }

    /**
     * Mark the accounts the server's ignore list names, unless a service holds them.
     */
    public function applyIgnores(ServerAccountImport $import): void
    {
        $this->query($import)->where('category', '!=', AccountImportMatcher::LINKED)
            ->whereIn('username', DB::table('server_import_ignores')->where('server_id', $import->server_id)->select('username'))
            ->update(['category' => self::IGNORED]);
    }

    /**
     * Which of these usernames are ignored.
     *
     * @param  list<string>  $usernames
     * @return list<string>
     */
    public function ignoredAmong(ServerAccountImport $import, array $usernames): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($usernames)), self::LOOKUP_CHUNK) as $chunk) {
            array_push($found, ...$this->query($import)->whereIn('username', $chunk)->where('category', self::IGNORED)->pluck('username')->all());
        }

        return $found;
    }

    /**
     * The whole list in position order, $size accounts at a time, so a caller holds one chunk of decrypted rows.
     *
     * @return Generator<int, list<array{username: string, payload: array<string, mixed>, category: string}>>
     */
    public function chunks(ServerAccountImport $import, int $size = self::LOOKUP_CHUNK): Generator
    {
        $last = -1;

        while (true) {
            $records = $this->query($import)->where('position', '>', $last)->orderBy('position')->limit($size)->get(['username', 'payload', 'category', 'position']);

            if ($records->isEmpty()) {
                return;
            }

            $last = (int) $records->last()->position;

            yield $records->map(fn (ServerAccountImportAccount $record): array => ['username' => (string) $record->username, 'payload' => $record->payload, 'category' => (string) $record->category])->all();
        }
    }

    /**
     * Rewrite accounts in place after matching again: the payload and the plain columns of each.
     *
     * @param  array<string, array<string, mixed>>  $rows  new row by username
     * @param  array<string, string>  $categories  plain category by username
     */
    public function rewrite(ServerAccountImport $import, array $rows, array $categories): void
    {
        foreach ($rows as $username => $row) {
            $this->query($import)->where('username', $username)->update([
                'category' => $categories[$username], 'client_id' => $row['client_id'] ?? null,
                'payload' => Crypt::encryptString(json_encode($row, JSON_THROW_ON_ERROR)),
            ]);
        }
    }

    /**
     * Recount the categories onto the import's columns.
     *
     * @return array<string, int>
     */
    public function refreshCounts(ServerAccountImport $import): array
    {
        $counts = $this->counts($import);
        $import->update([
            'proposed_count' => $counts[AccountImportMatcher::PROPOSED] ?? 0, 'unmatched_count' => $counts[AccountImportMatcher::UNMATCHED] ?? 0,
            'reseller_count' => $counts[AccountImportMatcher::RESELLER] ?? 0, 'linked_count' => $counts[AccountImportMatcher::LINKED] ?? 0,
        ]);

        return $counts;
    }

    /**
     * Move a list the previous release stored whole (server_account_imports.accounts) into rows, once. All or nothing:
     * the move runs in one transaction under the import's row lock and checks again inside it, so a failure part-way
     * leaves the list unmoved and a second reader waits, then finds it moved. Called by FetchAccountsForImport::latest()
     * and by the queued jobs, which may hold an import the previous release started.
     */
    public function adopt(ServerAccountImport $import): void
    {
        if ($import->account_count === 0 || $this->query($import)->exists()) {
            return;
        }

        DB::transaction(function () use ($import): void {
            $blob = DB::table('server_account_imports')->where('id', $import->id)->lockForUpdate()->value('accounts');

            if (! is_string($blob) || $blob === '' || $this->query($import)->exists()) {
                return;
            }

            try {
                $rows = json_decode(Crypt::decryptString($blob), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException|DecryptException $exception) {
                // Reported once: the blob is dropped, so no later view reads (and reports) it again.
                report($exception);
                DB::table('server_account_imports')->where('id', $import->id)->update(['accounts' => null]);
                $import->forceFill([
                    'account_count' => 0, 'proposed_count' => 0, 'unmatched_count' => 0, 'reseller_count' => 0, 'linked_count' => 0,
                    'error' => __('The stored list of accounts could not be read, so it was dropped. Fetch the accounts again.'),
                ] + (in_array($import->status, [ServerAccountImport::READY, ServerAccountImport::MATCHING], true) ? ['status' => ServerAccountImport::FAILED] : []))->save();

                return;
            }

            $this->replace($import, is_array($rows) ? array_values($rows) : []);
            DB::table('server_account_imports')->where('id', $import->id)->update(['accounts' => null]);
        });
    }

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), app(InstallSecrets::class)->get(self::EMAIL_HASH_SECRET));
    }

    /**
     * @return Builder<ServerAccountImportAccount>
     */
    private function query(ServerAccountImport $import): Builder
    {
        return ServerAccountImportAccount::query()->where('server_account_import_id', $import->id);
    }

    /**
     * @param  array<int, ServerAccountImportAccount>  $records
     * @return array<string, array<string, mixed>>
     */
    private function decode(array $records): array
    {
        $rows = [];

        foreach ($records as $record) {
            $rows[(string) $record->username] = self::shape($record);
        }

        return $rows;
    }

    /**
     * The account as its plain columns say: a linked or ignored row shows that, whatever its payload was written with
     * (link() and setIgnored() change only the columns).
     *
     * @return array<string, mixed>
     */
    private static function shape(ServerAccountImportAccount $record): array
    {
        $row = $record->payload;

        return match (true) {
            $record->category === ($row['category'] ?? null) => $row,
            $record->category === AccountImportMatcher::LINKED => [...$row, 'category' => AccountImportMatcher::LINKED, 'client_id' => null, 'product_id' => null, 'note' => null, 'differences' => []],
            default => [...$row, 'category' => (string) $record->category],
        };
    }
}
