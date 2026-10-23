<?php

namespace App\Modules\Services\Support;

use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Core\Support\Like;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerAccountImportAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * The accounts of a fetched list (cPanel spec §10), one row each in server_account_import_accounts. Callers filter and
 * count on the plain columns (username, domain, category, client_id, a keyed hash of the contact email) and decrypt
 * only the rows they show or act on, so a page view or an import chunk costs what it touches, not the whole list.
 * A row's `payload` is the whole account row, encrypted; nothing here reads a list whole except all().
 */
class AccountList
{
    /** The install secret keying the email hash: it stays the same when APP_KEY is rotated. */
    public const EMAIL_HASH_SECRET = 'services.import_email_hash';

    private const TABLE = 'server_account_import_accounts';

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
            // One row at a time: the ciphertext of a large list is not held beside its decrypted rows.
            $rows = [];

            foreach ($this->query($import)->orderBy('position')->select(['username', 'payload'])->cursor() as $record) {
                $rows[(string) $record->username] = $record->payload;
            }

            return $rows;
        }

        $found = [];

        foreach (array_chunk(array_values(array_unique($usernames)), self::LOOKUP_CHUNK) as $chunk) {
            array_push($found, ...$this->query($import)->whereIn('username', $chunk)->orderBy('position')->get(['username', 'payload'])->all());
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
            'rows' => array_values($this->decode($filtered()->orderBy('position')->offset(($page - 1) * $perPage)->limit($perPage)->get(['username', 'payload'])->values()->all())),
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
     * Mark accounts as linked to the services created for them, decrypting only those accounts.
     *
     * @param  array<string, int>  $services  service id by username
     */
    public function link(ServerAccountImport $import, array $services): void
    {
        foreach (array_chunk(array_keys($services), self::LOOKUP_CHUNK) as $chunk) {
            foreach ($this->rows($import, $chunk) as $username => $row) {
                $row = [...$row, 'category' => AccountImportMatcher::LINKED, 'client_id' => null, 'product_id' => null, 'service_id' => $services[$username], 'note' => null, 'differences' => []];
                $this->query($import)->where('username', $username)->update(['category' => AccountImportMatcher::LINKED, 'client_id' => null,
                    'payload' => Crypt::encryptString(json_encode($row, JSON_THROW_ON_ERROR))]);
            }
        }
    }

    /**
     * Move a list the previous release stored whole (server_account_imports.accounts) into rows, once; the import is
     * read through FetchAccountsForImport::latest(), which calls this. Idempotent, so two readers at once do no harm.
     */
    public function adopt(ServerAccountImport $import): void
    {
        if ($import->account_count === 0 || $this->query($import)->exists()) {
            return;
        }

        $blob = DB::table('server_account_imports')->where('id', $import->id)->value('accounts');

        if (! is_string($blob) || $blob === '') {
            return;
        }

        try {
            $rows = json_decode(Crypt::decryptString($blob), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        $this->replace($import, is_array($rows) ? array_values($rows) : []);
        DB::table('server_account_imports')->where('id', $import->id)->update(['accounts' => null]);
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
            $rows[(string) $record->username] = $record->payload;
        }

        return $rows;
    }
}
