<?php

namespace App\Modules\Services\Support;

use App\Modules\Services\Models\ServerAccountImport;
use Illuminate\Support\Facades\DB;

/**
 * What a fetched list takes over from the list it replaces (AccountImportMatcher::store(), under the server lock): the
 * import report, and the staff choices for accounts that are still the same account (same creation time on the
 * server, else the same domain). The replaced list and its accounts are deleted. "Match again" replaces none: it
 * keeps its own choices for accounts that did not change.
 */
class ImportListHandover
{
    public function __construct(private AccountList $list) {}

    /**
     * @param  list<array<string, mixed>>  $rows  the new list, classified
     * @return array<string, mixed> attributes to save on the new list
     */
    public function take(ServerAccountImport $import, array $rows): array
    {
        $previous = ServerAccountImport::query()->where('server_id', $import->server_id)->where('id', '<', $import->id)
            ->where('status', ServerAccountImport::READY)->latest('id')->first();
        $chosen = array_keys($import->selections ?? []);
        // Only the accounts with a choice are compared: their fingerprint is all that is read from the old list.
        $before = $this->list->rows($previous ?? $import, $chosen);
        $now = collect($rows)->keyBy('username');
        $selections = array_filter($import->selections ?? [], fn (string $username): bool => $now->has($username) && isset($before[$username])
            && self::fingerprint($before[$username]) === self::fingerprint($now->get($username)), ARRAY_FILTER_USE_KEY);
        $attributes = ['selections' => $selections ?: null];

        if ($previous !== null && $previous->imported_count + $previous->not_imported_count > 0) {
            DB::table('server_account_import_report_lines')->where('server_account_import_id', $previous->id)->update(['server_account_import_id' => $import->id]);
            $attributes += $previous->only(['imported_count', 'not_imported_count', 'stop_reason']);
        }

        ServerAccountImport::query()->where('server_id', $import->server_id)->where('id', '<', $import->id)->delete();

        return $attributes;
    }

    /**
     * What tells one account from a later one re-created under the same username: its creation time on the server,
     * else its domain.
     *
     * @param  array<string, mixed>  $account
     */
    private static function fingerprint(array $account): string
    {
        return (string) ($account['created_at'] ?? $account['domain'] ?? '');
    }
}
