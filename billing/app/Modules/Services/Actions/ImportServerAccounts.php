<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Core\CompanyClock;
use App\Data\Catalog\ProductSummary;
use App\Enums\BillingCycle;
use App\Events\Services\ServiceImported;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountImportMatcher;
use App\Modules\Services\Support\AccountImportPlan;
use App\Modules\Services\Support\AccountList;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import accounts, step 4 (cPanel spec §10): create a service for each account staff reviewed and confirmed, exactly as
 * the server holds it, on the queue in chunks (ImportReviewedAccounts).
 *
 * - start(): under the server lock, only when the reviewed rows still hold (AccountImportPlan::stillReviewed(), bound
 *   to the digest the review page showed); the request carries nothing else.
 * - handle(): one chunk. Each account is checked again and created in its own transaction: active, or suspended when
 *   the server has it suspended; no invoice, welcome mail, module command or password. The live-only unique
 *   (server_id, username) index makes a second import of the same account fail; it is reported as already held.
 *   ServiceImported follows each commit, and each account is audited against the staff member.
 *   Lock order (ADR 0021, client currency): the client FOR SHARE, then the service insert, its report line and the
 *   import's count. The service copies the client's currency, so the client's status and currency are checked again
 *   under that lock; a currency change (Client::updateDetails, FOR UPDATE) either waits for the service and is then
 *   refused, or commits first and the account is reported as not imported. No server is called: the accounts come
 *   from the stored list.
 * - finish(): imported accounts are marked linked in the stored list, the counts refreshed, the imported accounts'
 *   choices cleared, and the list kept another HOURS hours so the report is seen (a list still importing is never pruned).
 *
 * This is the explicit staff action security standards §7 asks for: only accounts staff named and confirmed are linked.
 */
class ImportServerAccounts
{
    /** Accounts per queued chunk. */
    public const CHUNK = 100;

    public function __construct(private AccountImportPlan $plan, private AccountImportMatcher $matcher, private AccountList $list, private ActivityLogger $activity, private CompanyClock $clock) {}

    /**
     * Record staff choices under the server lock, so nothing is written once an import has started: for $usernames in
     * the fetched list, every proposed account ($scope proposed: ticked, except where the matched client is flagged
     * now, AccountImportMatcher::clientFlags()), or every ticked one ($scope ticked).
     *
     * @param  'usernames'|'proposed'|'ticked'  $scope
     * @param  list<string>  $usernames
     * @param  array<string, mixed>  $choice
     * @return array{flagged: int, unpriced: int|null} proposed accounts left unticked because their client is flagged;
     *                                                 for the bulk cycle, the ticked accounts it leaves unpriced
     *
     * @throws ValidationException when the list is not ready
     */
    public function select(Server $server, string $scope, array $usernames, array $choice): array
    {
        [$flagged, $import] = DB::transaction(function () use ($server, $scope, $usernames, $choice): array {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();
            $import = FetchAccountsForImport::latest($server);

            if ($import?->status !== ServerAccountImport::READY) {
                throw ValidationException::withMessages(['import' => __('The accounts on :server cannot be changed now: fetch them again, or wait for the running step to finish.', ['server' => $server->name])]);
            }

            $selections = $import->selections ?? [];
            $flagged = [];
            $proposed = $scope === 'proposed' ? $this->list->clientsIn($import, AccountImportMatcher::PROPOSED) : [];
            $usernames = match ($scope) {
                'proposed' => array_keys($proposed),
                'ticked' => array_keys(array_filter($selections, fn (array $selected): bool => (bool) ($selected['include'] ?? false))),
                default => $this->list->existing($import, $usernames),
            };

            if (($choice['include'] ?? false) === true) {
                // An ignored account is brought back before it can be ticked.
                $usernames = array_values(array_diff($usernames, $this->list->ignoredAmong($import, $usernames)));
            }

            if ($scope === 'proposed') {
                // A client who changed email lately or never verified it may have taken an account's contact address
                // since the match: never ticked in bulk.
                $recent = array_keys($this->matcher->clientFlags(array_values(array_filter($proposed))));
                $flagged = array_keys(array_filter($proposed, fn (?int $clientId): bool => in_array($clientId, $recent, true)));
                $usernames = array_values(array_diff($usernames, $flagged));
            }

            foreach ($usernames as $username) {
                // A null choice returns that field to the account's default.
                $selections[$username] = array_filter([...($selections[$username] ?? []), ...$choice], fn (mixed $value): bool => $value !== null);
            }

            $import->update(['selections' => $selections]);

            return [count($flagged), $import];
        });

        // After the lock: how many ticked accounts the new cycle has no price for.
        $unpriced = $scope === 'ticked' && ($choice['cycle'] ?? null) !== null
            ? count(array_filter($this->plan->rows($server, $import), fn (array $row): bool => $row['decision']['client'] !== null && $row['decision']['product'] !== null && $row['decision']['amount'] === null))
            : null;

        return ['flagged' => $flagged, 'unpriced' => $unpriced];
    }

    /**
     * @throws ValidationException when there is nothing reviewed to import, or it changed since the review
     */
    public function start(Server $server, string $digest, ?User $staff): int
    {
        $import = DB::transaction(function () use ($server, $digest): ServerAccountImport {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();
            $import = FetchAccountsForImport::latest($server);

            if ($import?->status !== ServerAccountImport::READY) {
                throw ValidationException::withMessages(['import' => __('The accounts on :server are not ready to import: fetch them again, or wait for the running step to finish.', ['server' => $server->name])]);
            }

            $problem = $this->plan->stillReviewed($server, $import, $digest);

            if ($problem !== null || ($import->reviewed['rows'] ?? []) === []) {
                throw ValidationException::withMessages(['import' => $problem ?? __('Nothing to import: tick accounts and review them first.')]);
            }

            $lines = fn () => DB::table('server_account_import_report_lines')->where('server_account_import_id', $import->id);

            if ($import->stop_reason === null) {
                $lines()->delete();
            } else {
                // Going on after a stop: one report for both runs. The earlier run's lines stay, except for the
                // accounts this run tries again.
                foreach (array_chunk(array_column($import->reviewed['rows'], 'username'), 1000) as $chunk) {
                    $lines()->where('imported', false)->whereIn('username', $chunk)->delete();
                }
            }

            $earlier = ['imported_count' => $lines()->where('imported', true)->count(), 'not_imported_count' => $lines()->where('imported', false)->count()];
            // The lines already there when this run started, so its progress counts only its own accounts.
            // The list outlives its own run (#135): it would otherwise expire, with the report, while chunks still run.
            $import->update(['status' => ServerAccountImport::IMPORTING, ...$earlier, 'stop_reason' => null,
                'reviewed' => [...$import->reviewed, 'earlier' => array_sum($earlier)],
                'expires_at' => max($import->expires_at, now()->addHours(ServerAccountImport::HOURS))]);

            return $import;
        });

        $count = count($import->reviewed['rows'] ?? []);
        $this->activity->log("Import of {$count} accounts from server {$server->name} requested", $server, properties: [
            'accounts' => $count, 'digest' => $digest, 'usernames' => hash('sha256', implode(',', array_column($import->reviewed['rows'] ?? [], 'username'))),
            'reviewed_by' => $import->reviewed['staff_id'] ?? null,
        ]);
        ImportReviewedAccounts::dispatch($import->id, $staff?->id, digest: $digest);

        return $count;
    }

    /**
     * Import exactly the reviewed rows (or only $usernames among them) and report each: the client, product, cycle,
     * dates and price the review showed, never today's match. An account a live service now holds, a client that is
     * no longer active or a product that no longer provisions on the server is left out with the reason.
     *
     * @param  list<string>|null  $usernames
     * @return list<array{username: string, imported: bool, message: string, service_id: int|null}>
     */
    public function handle(Server $server, ServerAccountImport $import, ?User $staff, ?array $usernames = null): array
    {
        $reviewed = array_values(array_filter($import->reviewed['rows'] ?? [], fn (array $row): bool => $usernames === null || in_array($row['username'], $usernames, true)));
        $accounts = collect($this->list->rows($import, array_column($reviewed, 'username')));
        $clients = Client::query()->whereKey(array_column($reviewed, 'client_id'))->get(['id', 'status', 'currency'])->keyBy('id');
        $products = $this->matcher->serverProducts($server);
        // Live services holding a username in any case (older services may keep another case; WHM names are lowercase).
        $held = Service::query()->where('server_id', $server->id)->whereIn('status', ServiceStatus::occupyingValues())
            ->whereRaw('lower(username) in ('.implode(', ', array_fill(0, max(1, count($reviewed)), '?')).')', array_column($reviewed, 'username') ?: [''])
            ->pluck('username')->map(fn (string $username): string => strtolower($username))->flip();
        $report = [];

        foreach ($reviewed as $row) {
            $account = $accounts->get($row['username']);
            $client = $clients->get($row['client_id']);
            $product = $products[$row['product_id']] ?? null;
            $problem = match (true) {
                $account === null => AccountImportMatcher::text('The account is no longer in the fetched list.'),
                $held->has(strtolower($row['username'])) => AccountImportMatcher::text('A live service on this server already holds this account.'),
                default => $this->clientProblem($client, $row['currency']) ?? ($product === null ? AccountImportMatcher::text('The product no longer provisions on this server.') : null),
            };

            if ($problem !== null) {
                $report[] = $this->report($import, ['username' => $row['username'], 'imported' => false, 'message' => $problem, 'service_id' => null]);

                continue;
            }

            try {
                // The service and its report line commit together, so a chunk that fails later keeps both.
                $service = DB::transaction(function () use ($server, $import, $account, $row, $product, &$report): ?Service {
                    // The check above read the client without a lock; the service copies its currency, so decide again
                    // under a share lock that a currency change has to wait for (class docblock, lock order).
                    $problem = $this->clientProblem(Client::query()->whereKey($row['client_id'])->sharedLock()->first(['id', 'status', 'currency']), $row['currency']);

                    if ($problem !== null) {
                        $report[] = $this->report($import, ['username' => $row['username'], 'imported' => false, 'message' => $problem, 'service_id' => null]);

                        return null;
                    }

                    $service = $this->create($server, $account, $row, $product, $row['currency']);
                    $report[] = $this->report($import, ['username' => $row['username'], 'imported' => true, 'message' => AccountImportMatcher::text('Imported.'), 'service_id' => $service->id]);

                    return $service;
                });
            } catch (UniqueConstraintViolationException) {
                $report[] = $this->report($import, ['username' => $row['username'], 'imported' => false, 'message' => AccountImportMatcher::text('A live service on this server already holds this account.'), 'service_id' => null]);

                continue;
            }

            if ($service === null) {
                continue;
            }

            ServiceImported::dispatch($service->id, $service->client_id, $server->id, $server->driver, 'server_import');
            $this->activity->log("Service {$service->label()} imported from server {$server->name}", $service, $service->client_id, [
                'server' => $server->name, 'username' => $service->username, 'product' => $product->name, 'staff_id' => $staff?->id,
            ], actor: $staff);
        }

        return $report;
    }

    /**
     * Why a reviewed account can no longer go to this client: inactive, or in another currency than the review priced
     * it in (the service is never priced in the new currency).
     */
    private function clientProblem(?Client $client, string $currency): ?string
    {
        return match (true) {
            $client === null || $client->status !== 'active' => AccountImportMatcher::text('The client is no longer active.'),
            $client->currency !== $currency => AccountImportMatcher::text('The client\'s currency changed from :reviewed to :current since the review, and the account was priced in :reviewed. Review the account again.', ['reviewed' => $currency, 'current' => $client->currency]),
            default => null,
        };
    }

    /**
     * Append one line to the report (inside the row's transaction when it has one) and count it on the import. An
     * account has one line (unique per import and username): a chunk that runs twice reports nothing the second time.
     *
     * @param  array{username: string, imported: bool, message: string, service_id: int|null}  $line
     * @return array{username: string, imported: bool, message: string, service_id: int|null}
     */
    private function report(ServerAccountImport $import, array $line): array
    {
        // The line and its count commit together (a savepoint inside the row's own transaction).
        DB::transaction(function () use ($import, $line): void {
            $added = DB::table('server_account_import_report_lines')->insertOrIgnore([...$line, 'server_account_import_id' => $import->id, 'message' => mb_substr($line['message'], 0, 255), 'created_at' => now()]);

            if ($added > 0) {
                $import->increment($line['imported'] ? 'imported_count' : 'not_imported_count');
            }
        });

        return $line;
    }

    /**
     * Stop a confirmed import early: the reason on the import, and each reviewed account not reached reported as not
     * imported with it.
     */
    public function stop(Server $server, ServerAccountImport $import, ?User $staff, string $reason): void
    {
        $reason = mb_substr($reason, 0, 255);

        // One transaction: the reason first, then every account not reached in batches, then one count.
        DB::transaction(function () use ($import, $reason): void {
            $import->update(['stop_reason' => $reason]);
            $reached = DB::table('server_account_import_report_lines')->where('server_account_import_id', $import->id)->pluck('username')->flip();
            $missing = array_values(array_filter(array_column($import->reviewed['rows'] ?? [], 'username'), fn (string $username): bool => ! $reached->has($username)));
            $message = AccountImportMatcher::text('Not reached.');

            foreach (array_chunk($missing, 1000) as $chunk) {
                DB::table('server_account_import_report_lines')->insert(array_map(fn (string $username): array => [
                    'server_account_import_id' => $import->id, 'username' => $username, 'imported' => false, 'not_reached' => true, 'service_id' => null, 'message' => $message, 'created_at' => now(),
                ], $chunk));
            }

            if ($missing !== []) {
                $import->increment('not_imported_count', count($missing));
            }
        });

        $this->finish($server, $import, $staff);
    }

    /**
     * The import is done: imported accounts become linked rows, the counts are refreshed and their choices cleared. The
     * list is kept another HOURS hours from now, so a report of an import that ran past the list's own expiry is seen.
     */
    public function finish(Server $server, ServerAccountImport $import, ?User $staff): void
    {
        // Only this run's accounts become linked and lose their choices; an earlier run's (before a stop) were done
        // then, and Fetch again may have matched them afresh since (their service may have ended).
        $services = array_intersect_key(DB::table('server_account_import_report_lines')->where('server_account_import_id', $import->id)->where('imported', true)
            ->pluck('service_id', 'username')->all(), array_flip(array_column($import->reviewed['rows'] ?? [], 'username')));
        $this->list->link($import, array_map('intval', array_filter($services)));
        $counts = $this->list->counts($import);
        $run = count($services);
        $import->update([
            // Imported accounts are done; the choices of every other ticked account are kept, so a stopped import can go on.
            'status' => ServerAccountImport::READY, 'reviewed' => null,
            'selections' => array_diff_key($import->selections ?? [], $services) ?: null,
            'proposed_count' => $counts[AccountImportMatcher::PROPOSED] ?? 0, 'unmatched_count' => $counts[AccountImportMatcher::UNMATCHED] ?? 0,
            'reseller_count' => $counts[AccountImportMatcher::RESELLER] ?? 0, 'linked_count' => $counts[AccountImportMatcher::LINKED] ?? 0,
            'expires_at' => max($import->expires_at, now()->addHours(ServerAccountImport::HOURS)),
        ]);
        $this->activity->log("Imported {$run} accounts from server {$server->name}", $server, properties: ['imported' => $run, 'not_imported' => $import->not_imported_count, 'stopped' => $import->stop_reason], actor: $staff);
    }

    /**
     * @param  array<string, mixed>  $account  the account as stored with the fetched list
     * @param  array{username: string, client_id: int, product_id: int, cycle: string, next_due_date: string, anchor_day: int, amount: int, currency: string}  $row  the reviewed row
     */
    private function create(Server $server, array $account, array $row, ProductSummary $product, string $currency): Service
    {
        $now = CarbonImmutable::now();
        $due = CarbonImmutable::parse($row['next_due_date']);
        $suspended = (bool) ($account['suspended'] ?? false);
        $created = isset($account['created_at']) ? CarbonImmutable::parse($account['created_at']) : null;

        return Service::query()->create([
            'client_id' => $row['client_id'],
            'product_id' => $product->id,
            'product_name' => $product->name,
            'status' => $suspended ? ServiceStatus::Suspended : ServiceStatus::Active,
            'cycle' => BillingCycle::from($row['cycle']),
            'currency' => $currency,
            'domain' => $account['domain'] ?? null,
            'server_id' => $server->id,
            'username' => $row['username'],
            'first_payment_amount' => 0,
            'recurring_amount' => $row['amount'],
            'recurring_cycles_limit' => $product->recurringCycleLimit ?? 0,
            'registration_date' => $this->clock->localDate($created ?? $now),
            'billing_anchor_day' => $row['anchor_day'],
            'next_due_date' => $due,
            'next_invoice_date' => $due,
            'activated_at' => $now,
            'suspended_at' => $suspended ? $now : null,
            'suspension_reason' => $suspended ? 'staff:'.mb_substr('imported (suspended on the server'.(($account['suspend_reason'] ?? null) ? ': '.$account['suspend_reason'] : '').')', 0, 190) : null,
            'driver_data' => array_filter([
                'owner' => $account['owner'] ?? null,
                'plan' => $account['plan'] ?? null,
                'ip' => $account['ip'] ?? null,
                'created_at' => $account['created_at'] ?? null,
            ]),
        ]);
    }
}
