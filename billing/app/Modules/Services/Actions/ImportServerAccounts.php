<?php

namespace App\Modules\Services\Actions;

use App\Enums\BillingCycle;
use App\Events\Services\ServiceImported;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountImportMatcher;
use App\Modules\Services\Support\AccountImportPlan;
use App\Modules\Services\Support\Catalog;
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

    public function __construct(private AccountImportPlan $plan, private Catalog $catalog, private ActivityLogger $activity) {}

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
        $this->activity->log("Import of {$count} accounts from server {$server->name} requested", $server, properties: ['accounts' => $count]);
        ImportReviewedAccounts::dispatch($import->id, $staff?->id);

        return $count;
    }

    /**
     * Import the ticked accounts (or only $usernames among them) and report each.
     *
     * @param  list<string>|null  $usernames
     * @return list<array{username: string, imported: bool, message: string, service_id: int|null}>
     */
    public function handle(Server $server, ServerAccountImport $import, ?User $staff, ?array $usernames = null): array
    {
        $report = [];
        $only = $usernames === null ? null : array_flip($usernames);

        foreach ($this->plan->rows($server, $import) as $row) {
            if ($only !== null && ! isset($only[$row['username']])) {
                continue;
            }

            $decision = $row['decision'];

            if ($decision['problems'] !== []) {
                $report[] = ['username' => $row['username'], 'imported' => false, 'message' => implode(' ', $decision['problems']), 'service_id' => null];

                continue;
            }

            try {
                $service = DB::transaction(fn (): Service => $this->create($server, $row, $decision));
            } catch (UniqueConstraintViolationException) {
                $report[] = ['username' => $row['username'], 'imported' => false, 'message' => __('A live service on this server already holds this account.'), 'service_id' => null];

                continue;
            }

            ServiceImported::dispatch($service->id, $service->client_id, $server->id, $server->driver, 'server_import');
            $this->activity->log("Service {$service->label()} imported from server {$server->name}", $service, $service->client_id, [
                'server' => $server->name, 'username' => $service->username, 'product' => $decision['product']['name'], 'staff_id' => $staff?->id,
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
     * @param  array<string, mixed>  $row
     * @param  array{client: array{id: int, currency: string}, product: array{id: int, name: string}, cycle: string, next_due_date: string, anchor_day: int, amount: int}  $decision
     */
    private function create(Server $server, array $row, array $decision): Service
    {
        $now = CarbonImmutable::now();
        $due = CarbonImmutable::parse($decision['next_due_date']);
        $anchor = $decision['anchor_day'];
        $suspended = (bool) ($row['suspended'] ?? false);
        $created = isset($row['created_at']) ? CarbonImmutable::parse($row['created_at']) : null;

        return Service::query()->create([
            'client_id' => $decision['client']['id'],
            'product_id' => $decision['product']['id'],
            'product_name' => $decision['product']['name'],
            'status' => $suspended ? ServiceStatus::Suspended : ServiceStatus::Active,
            'cycle' => BillingCycle::from($decision['cycle']),
            'currency' => $decision['client']['currency'],
            'domain' => $row['domain'] ?? null,
            'server_id' => $server->id,
            'username' => $row['username'],
            'first_payment_amount' => 0,
            'recurring_amount' => $decision['amount'],
            'recurring_cycles_limit' => $this->catalog->product($decision['product']['id'])->recurringCycleLimit ?? 0,
            'registration_date' => ($created ?? $now)->toDateString(),
            'billing_anchor_day' => $anchor,
            'next_due_date' => $due,
            'next_invoice_date' => $due,
            'activated_at' => $now,
            'suspended_at' => $suspended ? $now : null,
            'suspension_reason' => $suspended ? 'staff:'.mb_substr('Imported (suspended on the server'.(($row['suspend_reason'] ?? null) ? ': '.$row['suspend_reason'] : '').')', 0, 190) : null,
            'driver_data' => array_filter([
                'owner' => $row['owner'] ?? null,
                'plan' => $row['plan'] ?? null,
                'ip' => $row['ip'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ]),
        ]);
    }
}
