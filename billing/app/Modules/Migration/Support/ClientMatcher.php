<?php

namespace App\Modules\Migration\Support;

use App\Models\User;
use App\Modules\Core\Import\ClientImporter;
use App\Modules\Core\Import\LoginImporter;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Like;
use App\Modules\Migration\Models\MigrationIdMap;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Support\Clients\ClientCurrencyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides what each checked row does, by the duplicate rules of client-import spec §7: in-file duplicates, the id
 * map, email matches (merged, closed, recently changed or several), and optionally company name and tax id. Each
 * lookup is one query per chunk, never one per row.
 */
final class ClientMatcher
{
    /** Fields an update can change, compared with the client's current values. */
    private const COMPARED = ['first_name', 'last_name', 'company_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state',
        'postcode', 'country', 'tax_id', 'tax_exempt', 'currency', 'client_group_id', 'language', 'status', 'notes'];

    private const LEGAL_SUFFIXES = '/\b(private limited|pvt ltd|pvt|ltd|limited|llc|inc|incorporated|gmbh|plc|co)\b/';

    /**
     * @param  list<array{row: int, source_id: string, email_hash: string|null, client: array<string, mixed>, blank: list<string>, checksum: string, problems: list<array<string, mixed>>, payload?: array{extra?: array<string, mixed>}}>  $rows
     * @return list<array{decision: string|null, match_client_id: int|null, problems: list<array<string, mixed>>, changes: list<string>}>
     */
    public function decide(MigrationRun $run, array $rows, bool $hasExternalId): array
    {
        $options = $run->options ?? [];
        $first = $rows[0]['row'];
        $earlierEmails = $this->earlier($run, 'email_hash', array_values(array_filter(array_column($rows, 'email_hash'))), $first);
        $earlierIds = $hasExternalId ? $this->earlier($run, 'source_id', array_column($rows, 'source_id'), $first) : [];
        $mapped = MigrationIdMap::query()->where('migration_source_id', $run->migration_source_id)->where('entity', 'client')
            ->whereIn('source_id', array_column($rows, 'source_id'))->get(['source_id', 'target_id', 'checksum'])->keyBy('source_id');
        $emails = array_values(array_unique(array_filter(array_map(fn (array $row): ?string => $row['client']['email'] ?? null, $rows))));
        $byEmail = $this->clients(fn (Builder $query) => $query->whereIn(DB::raw('lower(email)'), $emails));
        $survivors = $this->clients(fn (Builder $query) => $query->whereKey($byEmail->pluck('merged_into_client_id')->filter()->all()))->keyBy('id');
        $targets = $this->clients(fn (Builder $query) => $query->whereKey($mapped->pluck('target_id')->all()))->keyBy('id');
        $contacts = Contact::query()->whereIn(DB::raw('lower(email)'), $emails)->pluck('email')->map(fn (string $email): string => mb_strtolower($email))->flip();
        $logins = User::query()->whereIn(DB::raw('lower(email)'), $emails)->get(['id', 'email', 'is_staff', 'email_verified_at'])->keyBy(fn (User $login): string => mb_strtolower($login->email));
        $createLogins = (bool) ($options['create_logins'] ?? true);
        $companies = ($options['match_company'] ?? false) ? $this->companies($rows) : [];
        $taxIds = ($options['match_tax_id'] ?? false) ? $this->taxIds($rows) : [];
        $byEmail = $byEmail->groupBy(fn (Client $client): string => mb_strtolower($client->email));
        $seenEmails = [];
        $seenIds = [];
        $results = [];

        foreach ($rows as $row) {
            $problems = $row['problems'];
            $email = $row['client']['email'] ?? null;
            $note = function (string $severity, string $code, string $message, ?int $at = null) use (&$problems): void {
                $problems[] = array_filter(['severity' => $severity, 'code' => $code, 'message' => $message, 'row' => $at]);
            };

            if ($row['email_hash'] !== null && ($at = $earlierEmails[$row['email_hash']] ?? $seenEmails[$row['email_hash']] ?? null) !== null) {
                $note('error', 'duplicate_email', __('Same email as row :row.', ['row' => number_format($at)]), $at);
            }

            if ($hasExternalId && ($at = $earlierIds[$row['source_id']] ?? $seenIds[$row['source_id']] ?? null) !== null) {
                $note('error', 'duplicate_external_id', __('Same External ID as row :row.', ['row' => number_format($at)]), $at);
            }

            if ($row['email_hash'] !== null) {
                $seenEmails[$row['email_hash']] ??= $row['row'];
            }
            $seenIds[$row['source_id']] ??= $row['row'];

            if ($email !== null && $contacts->has($email)) {
                $note('warning', 'contact_email', __('This email also belongs to a contact of another client.'));
            }

            [$decision, $match] = [null, null];

            if (collect($problems)->doesntContain('severity', 'error')) {
                [$decision, $match] = match (true) {
                    $mapped->has($row['source_id']) => $this->mappedDecision($row, $mapped[$row['source_id']], $targets, $note),
                    default => $this->emailDecision($row, $byEmail->get((string) $email, collect()), $survivors, $companies, $taxIds, $options, $note),
                };
            }

            // An import never links an existing login on its own (spec §6.11): a row that may create a client needs a
            // decision, and "link" is offered only for a login whose email was verified at least 30 days ago.
            $login = $email === null ? null : $logins->get($email);
            $mayCreate = $decision === 'create' || ($decision === 'needs_decision' && in_array('possible_duplicate', array_column($problems, 'code'), true));

            if ($mayCreate && $createLogins && $login !== null && ($row['client']['status'] ?? 'active') !== 'closed') {
                if ($login->is_staff) {
                    $note('warning', 'staff_login', __('This email belongs to a staff login, so the new client gets no login.'));
                } elseif (LoginImporter::linkable($login)) {
                    $note('warning', 'existing_login', __('A login with this email already exists. Link the new client to it, or create the client without a login.'));
                    $decision = 'needs_decision';
                } else {
                    $note('warning', 'existing_login_recent', __('A login with this email exists, but its address was verified less than 30 days ago or never, so it cannot be linked. Create the client without a login, or skip the row.'));
                    $decision = 'needs_decision';
                }
            }

            // An import never changes an existing client's email.
            if ($match instanceof Client && $email !== null && mb_strtolower($match->email) !== $email) {
                $note('info', 'email_kept', __('Email in the file differs: kept the current one.'));
            }

            $changes = $match instanceof Client && in_array($decision, ['update', 'unchanged'], true) ? $this->changes($row, $match, ($options['blank_cells'] ?? 'keep') === 'keep') : [];
            $results[] = [
                // A changed opening balance alone is still an update: Billing posts the difference (spec §6.12).
                'decision' => $decision === 'update' && $changes === [] && ($row['payload']['extra']['credit_balance'] ?? null) === null ? 'unchanged' : $decision,
                'match_client_id' => $match?->id,
                'problems' => $problems,
                'changes' => $changes,
            ];
        }

        return $this->lockCurrencies($results);
    }

    /**
     * A currency change on a client that already has invoices, services, orders, payments or credit is a row error
     * (spec §6.4): its amounts are stored in that currency. One kernel lookup for the chunk (ClientCurrencyUsage).
     *
     * @param  list<array{decision: string|null, match_client_id: int|null, problems: list<array<string, mixed>>, changes: list<string>}>  $results
     * @return list<array{decision: string|null, match_client_id: int|null, problems: list<array<string, mixed>>, changes: list<string>}>
     */
    public function lockCurrencies(array $results): array
    {
        $changing = array_values(array_filter(array_map(fn (array $result): ?int => in_array('currency', $result['changes'], true) ? $result['match_client_id'] : null, $results)));
        $locked = array_flip(app(ClientCurrencyUsage::class)->clientsInUse($changing));

        return array_map(fn (array $result): array => in_array('currency', $result['changes'], true) && isset($locked[$result['match_client_id']]) ? [
            ...$result,
            'decision' => null,
            'changes' => [],
            'problems' => [...$result['problems'], ['severity' => 'error', 'code' => 'currency_locked', 'message' => __('The currency cannot change: this client already has invoices, services, orders, payments or credit in its currency.'), 'field' => 'currency']],
        ] : $result, $results);
    }

    /**
     * A row imported from this source before: unchanged by checksum, otherwise an update, whatever its email now is.
     *
     * @param  array{checksum: string}  $row
     * @param  Collection<int, Client>  $targets
     * @return array{0: string|null, 1: Client|null}
     */
    private function mappedDecision(array $row, MigrationIdMap $map, Collection $targets, \Closure $note): array
    {
        $client = $targets->get($map->target_id);

        if ($client === null) {
            $note('error', 'target_deleted', __('The client imported from this row earlier was deleted. Nothing is created.'));

            return [null, null];
        }

        if ($client->status === 'closed') {
            $note('warning', 'closed', __('The client imported from this row earlier is closed. Choose whether to update it.'));

            return ['needs_decision', $client];
        }

        return [$map->checksum === $row['checksum'] ? 'unchanged' : 'update', $client];
    }

    /**
     * @param  array{row: int, client: array<string, mixed>}  $row
     * @param  Collection<int, Client>  $matches
     * @param  Collection<int, Client>  $survivors
     * @param  array<string, Client>  $companies
     * @param  array<string, Client>  $taxIds
     * @param  array<string, mixed>  $options
     * @return array{0: string|null, 1: Client|null}
     */
    private function emailDecision(array $row, Collection $matches, Collection $survivors, array $companies, array $taxIds, array $options, \Closure $note): array
    {
        $matches = $matches->map(function (Client $client) use ($survivors, $note): Client {
            if ($client->merged_into_client_id !== null && $survivors->has($client->merged_into_client_id)) {
                $note('info', 'merged', __('The matching client was merged; the client it was merged into is used.'));

                return $survivors[$client->merged_into_client_id];
            }

            return $client;
        })->unique('id')->values();

        if ($matches->count() > 1) {
            $note('warning', 'several_matches', __(':count clients have this email. Choose one or skip the row.', ['count' => $matches->count()]));

            return ['needs_decision', null];
        }

        $client = $matches->first();

        if ($client === null) {
            $possible = $companies[$this->companyKey($row['client']['company_name'] ?? null) ?? ''] ?? $taxIds[$this->taxKey($row['client']['tax_id'] ?? null) ?? ''] ?? null;

            if ($possible !== null) {
                $note('warning', 'possible_duplicate', __('A client with the same company name or tax ID exists. Create a new client, update that one, or skip.'));

                return ['needs_decision', $possible];
            }

            if (($row['client']['status'] ?? 'active') === 'closed' && ($options['closed_clients'] ?? 'import') === 'skip') {
                $note('info', 'closed_skipped', __('Closed clients are skipped by the import options.'));

                return ['skip', null];
            }

            if (($row['client']['status'] ?? 'active') === 'closed') {
                $note('info', 'closed_created', __('Imported as a closed client, without a login.'));
            }

            return ['create', null];
        }

        if ($client->status === 'closed') {
            $note('warning', 'closed', __('The client with this email is closed. Choose whether to update it.'));

            return ['needs_decision', $client];
        }

        if ($client->email_changed_at?->gt(CarbonImmutable::now()->subDays(30))) {
            $note('warning', 'email_changed', __('The client with this email changed it in the last 30 days. Check the match.'));

            return ['needs_decision', $client];
        }

        if (($options['on_match'] ?? 'skip') === 'skip') {
            $note('info', 'exists', __('A client with this email exists. It is skipped by the import options.'));

            return ['skip', $client];
        }

        return ['update', $client];
    }

    /**
     * @param  array{client: array<string, mixed>, blank: list<string>}  $row
     * @return list<string>
     */
    public function changes(array $row, Client $client, bool $keepBlank): array
    {
        // Only mapped cells, never the email: a filled one that differs, or (when blank cells clear) an empty one over a value.
        $blank = $keepBlank ? [] : array_intersect(array_map(fn (string $target): string => $target === 'client_group' ? 'client_group_id' : $target, $row['blank']), ClientImporter::CLEARABLE);

        return array_values(array_filter(self::COMPARED, fn (string $field): bool => $field !== 'email'
            // A re-run never reopens a client closed here.
            && ! ($field === 'status' && $client->status === 'closed')
            && match (true) {
                array_key_exists($field, $row['client']) => (string) $client->getAttribute($field) !== (string) $row['client'][$field],
                in_array($field, $blank, true) => ! in_array($client->getAttribute($field), [null, ''], true),
                default => false,
            }));
    }

    /**
     * The first row number of each value among rows checked before this chunk.
     *
     * @param  list<string>  $values
     * @return array<string, int>
     */
    private function earlier(MigrationRun $run, string $column, array $values, int $before): array
    {
        return MigrationRecord::query()->where('migration_run_id', $run->id)->where('row_number', '<', $before)
            ->whereIn($column, array_values(array_unique($values)))->groupBy($column)
            ->selectRaw($column === 'email_hash' ? 'email_hash as value, min(row_number) as first_row' : 'source_id as value, min(row_number) as first_row')
            ->pluck('first_row', 'value')
            ->map(fn (mixed $row): int => (int) $row)->all();
    }

    /**
     * @param  \Closure(Builder<Client>): Builder<Client>  $scope
     * @return Collection<int, Client>
     */
    private function clients(\Closure $scope): Collection
    {
        return $scope(Client::query())->orderBy('id')->get(['id', ...self::COMPARED, 'merged_into_client_id', 'email_changed_at']);
    }

    /**
     * Existing clients whose company name matches a row's, compared without case, punctuation or legal suffixes.
     * Candidates share the name's first word; at most five queries per chunk.
     *
     * @param  list<array{client: array<string, mixed>}>  $rows
     * @return array<string, Client>
     */
    private function companies(array $rows): array
    {
        $keys = array_values(array_unique(array_filter(array_map(fn (array $row): ?string => $this->companyKey($row['client']['company_name'] ?? null), $rows))));
        $found = [];

        foreach (array_chunk(array_values(array_unique(array_map(fn (string $key): string => strtok($key, ' ') ?: $key, $keys))), 200) as $words) {
            $candidates = Client::query()->where(function (Builder $query) use ($words): void {
                foreach ($words as $word) {
                    Like::where($query, 'company_name', Like::startsWith($word), 'or');
                }
            })->orderBy('id')->get(['id', ...self::COMPARED, 'merged_into_client_id', 'email_changed_at']);

            foreach ($candidates as $client) {
                $key = $this->companyKey($client->company_name);
                if ($key !== null && in_array($key, $keys, true)) {
                    $found[$key] ??= $client;
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<array{client: array<string, mixed>}>  $rows
     * @return array<string, Client>
     */
    private function taxIds(array $rows): array
    {
        $keys = array_values(array_unique(array_filter(array_map(fn (array $row): ?string => $this->taxKey($row['client']['tax_id'] ?? null), $rows))));

        return $this->clients(fn (Builder $query) => $query->whereIn(DB::raw("replace(upper(tax_id), ' ', '')"), $keys))
            ->keyBy(fn (Client $client): string => (string) $this->taxKey($client->tax_id))->all();
    }

    private function companyKey(?string $name): ?string
    {
        $key = trim((string) preg_replace(['/[^\p{L}\p{N} ]+/u', self::LEGAL_SUFFIXES, '/\s+/'], ['', '', ' '], mb_strtolower((string) $name)));

        return $key === '' ? null : $key;
    }

    private function taxKey(?string $taxId): ?string
    {
        $key = str_replace(' ', '', mb_strtoupper((string) $taxId));

        return $key === '' ? null : $key;
    }
}
