<?php

namespace App\Modules\Services\Actions;

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
 * - finish(): imported accounts are marked linked in the stored list, the counts refreshed, the choices cleared.
 *
 * This is the explicit staff action security standards §7 asks for: only accounts staff named and confirmed are linked.
 */
class ImportServerAccounts
{
    /** Accounts per queued chunk. */
    public const CHUNK = 100;

    public function __construct(private AccountImportPlan $plan, private AccountImportMatcher $matcher, private ActivityLogger $activity) {}

    /**
     * Tick or untick accounts in the fetched list ($usernames null: every proposed account), under the server lock, so
     * nothing is written once an import has started.
     *
     * @param  list<string>|null  $usernames
     *
     * @throws ValidationException when the list is not ready
     */
    public function tick(Server $server, ?array $usernames, bool $include): void
    {
        DB::transaction(function () use ($server, $usernames, $include): void {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();
            $import = FetchAccountsForImport::latest($server, withAccounts: true);

            if ($import?->status !== ServerAccountImport::READY) {
                throw ValidationException::withMessages(['import' => __('The accounts on :server cannot be changed now: fetch them again, or wait for the running step to finish.', ['server' => $server->name])]);
            }

            $accounts = collect($import->accounts ?? [])->keyBy('username');
            $selections = $import->selections ?? [];

            foreach ($usernames === null ? $accounts->where('category', AccountImportMatcher::PROPOSED)->keys()->all() : array_intersect(array_unique($usernames), $accounts->keys()->all()) as $username) {
                $selections[$username] = ['include' => $include];
            }

            $import->update(['selections' => $selections]);
        });
    }

    /**
     * @throws ValidationException when there is nothing reviewed to import, or it changed since the review
     */
    public function start(Server $server, string $digest, ?User $staff): int
    {
        $import = DB::transaction(function () use ($server, $digest): ServerAccountImport {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();
            $import = FetchAccountsForImport::latest($server, withAccounts: true);

            if ($import?->status !== ServerAccountImport::READY) {
                throw ValidationException::withMessages(['import' => __('The accounts on :server are not ready to import: fetch them again, or wait for the running step to finish.', ['server' => $server->name])]);
            }

            $problem = $this->plan->stillReviewed($server, $import, $digest);

            if ($problem !== null || ($import->reviewed['rows'] ?? []) === []) {
                throw ValidationException::withMessages(['import' => $problem ?? __('Nothing to import: tick accounts and review them first.')]);
            }

            $import->update(['status' => ServerAccountImport::IMPORTING, 'report' => []]);

            return $import;
        });

        $count = count($import->reviewed['rows'] ?? []);
        $this->activity->log("Import of {$count} accounts from server {$server->name} requested", $server, properties: [
            'accounts' => $count, 'digest' => $digest, 'usernames' => hash('sha256', implode(',', array_column($import->reviewed['rows'] ?? [], 'username'))),
            'reviewed_by' => $import->reviewed['staff_id'] ?? null,
        ]);
        ImportReviewedAccounts::dispatch($import->id, $staff?->id);

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
        $accounts = collect($import->accounts ?? [])->keyBy('username');
        $clients = Client::query()->whereKey(array_column($reviewed, 'client_id'))->get(['id', 'status', 'currency'])->keyBy('id');
        $products = $this->matcher->serverProducts($server);
        $report = [];

        foreach ($reviewed as $row) {
            $account = $accounts->get($row['username']);
            $client = $clients->get($row['client_id']);
            $product = $products[$row['product_id']] ?? null;
            $problem = match (true) {
                $account === null => __('The account is no longer in the fetched list.'),
                $client === null || $client->status !== 'active' => __('The client is no longer active.'),
                $product === null => __('The product no longer provisions on this server.'),
                default => null,
            };

            if ($problem !== null) {
                $report[] = ['username' => $row['username'], 'imported' => false, 'message' => $problem, 'service_id' => null];

                continue;
            }

            try {
                $service = DB::transaction(fn (): Service => $this->create($server, $account, $row, $product, $client->currency));
            } catch (UniqueConstraintViolationException) {
                $report[] = ['username' => $row['username'], 'imported' => false, 'message' => __('A live service on this server already holds this account.'), 'service_id' => null];

                continue;
            }

            ServiceImported::dispatch($service->id, $service->client_id, $server->id, $server->driver, 'server_import');
            $this->activity->log("Service {$service->label()} imported from server {$server->name}", $service, $service->client_id, [
                'server' => $server->name, 'username' => $service->username, 'product' => $product->name, 'staff_id' => $staff?->id,
            ], actor: $staff);
            $report[] = ['username' => $row['username'], 'imported' => true, 'message' => __('Imported just now.'), 'service_id' => $service->id];
        }

        return $report;
    }

    /**
     * The import is done: imported accounts become linked rows, the counts are refreshed and the choices cleared.
     */
    public function finish(Server $server, ServerAccountImport $import, ?User $staff): void
    {
        $report = $import->report ?? [];
        $services = array_column(array_filter($report, fn (array $line): bool => $line['imported']), 'service_id', 'username');
        $accounts = $import->accounts ?? [];

        foreach ($accounts as &$row) {
            if (isset($services[$row['username']])) {
                $row = [...$row, 'category' => AccountImportMatcher::LINKED, 'client_id' => null, 'product_id' => null, 'service_id' => $services[$row['username']], 'note' => null, 'differences' => []];
            }
        }

        unset($row);
        $counts = array_count_values(array_column($accounts, 'category'));
        $import->update([
            'status' => ServerAccountImport::READY, 'accounts' => $accounts, 'selections' => null, 'reviewed' => null,
            'proposed_count' => $counts[AccountImportMatcher::PROPOSED] ?? 0, 'unmatched_count' => $counts[AccountImportMatcher::UNMATCHED] ?? 0,
            'reseller_count' => $counts[AccountImportMatcher::RESELLER] ?? 0, 'linked_count' => $counts[AccountImportMatcher::LINKED] ?? 0,
        ]);
        $this->activity->log('Imported '.count($services)." accounts from server {$server->name}", $server, properties: ['imported' => count($services), 'not_imported' => count($report) - count($services)], actor: $staff);
    }

    /**
     * @param  array<string, mixed>  $account  the account as stored with the fetched list
     * @param  array{username: string, client_id: int, product_id: int, cycle: string, next_due_date: string, anchor_day: int, amount: int}  $row  the reviewed row
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
            'registration_date' => ($created ?? $now)->toDateString(),
            'billing_anchor_day' => $row['anchor_day'],
            'next_due_date' => $due,
            'next_invoice_date' => $due,
            'activated_at' => $now,
            'suspended_at' => $suspended ? $now : null,
            'suspension_reason' => $suspended ? 'staff:'.mb_substr('Imported (suspended on the server'.(($account['suspend_reason'] ?? null) ? ': '.$account['suspend_reason'] : '').')', 0, 190) : null,
            'driver_data' => array_filter([
                'owner' => $account['owner'] ?? null,
                'plan' => $account['plan'] ?? null,
                'ip' => $account['ip'] ?? null,
                'created_at' => $account['created_at'] ?? null,
            ]),
        ]);
    }
}
