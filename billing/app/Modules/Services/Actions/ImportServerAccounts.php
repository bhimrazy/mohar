<?php

namespace App\Modules\Services\Actions;

use App\Enums\BillingCycle;
use App\Events\Services\ServiceImported;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountImportPlan;
use App\Modules\Services\Support\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Import accounts, step 4 (cPanel spec §10): create a service for each account staff ticked and confirmed, exactly as
 * the server holds it. Everything is read from the stored list and today's data (AccountImportPlan re-matches and
 * re-checks each account); the request carries nothing but the confirmation. Active, or suspended when the server has it suspended. No invoice, welcome mail or module command, and no
 * password (the client sets one). One transaction per row; the live-only unique (server_id, username) index makes a
 * second import of the same account fail, so it is reported as already linked. ServiceImported follows each commit.
 *
 * This is the explicit staff action security standards §7 asks for: only accounts staff named and confirmed are linked.
 */
class ImportServerAccounts
{
    public function __construct(private AccountImportPlan $plan, private Catalog $catalog, private ActivityLogger $activity) {}

    /**
     * @return list<array{username: string, imported: bool, message: string, service_id: int|null}>
     */
    public function handle(Server $server, ServerAccountImport $import, ?User $staff): array
    {
        $report = [];

        foreach ($this->plan->rows($server, $import) as $row) {
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
            $report[] = ['username' => $row['username'], 'imported' => true, 'message' => __('Imported as :status.', ['status' => $service->status->value]), 'service_id' => $service->id];
        }

        $imported = count(array_filter($report, fn (array $line): bool => $line['imported']));
        $this->activity->log("Imported {$imported} accounts from server {$server->name}", $server, properties: ['imported' => $imported, 'not_imported' => count($report) - $imported]);
        $import->update(['selections' => null]);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{client: array{id: int, currency: string}, product: array{id: int, name: string}, cycle: string, next_due_date: string, amount: int}  $decision
     */
    private function create(Server $server, array $row, array $decision): Service
    {
        $now = CarbonImmutable::now();
        $due = CarbonImmutable::parse($decision['next_due_date']);
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
            'billing_anchor_day' => $due->day,
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
