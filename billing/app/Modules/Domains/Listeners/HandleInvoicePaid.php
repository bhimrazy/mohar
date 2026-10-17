<?php

namespace App\Modules\Domains\Listeners;

use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Events\Billing\InvoicePaid;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\RenewDomain;
use App\Modules\Domains\Jobs\TransferDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Paid domain lines (BillableType::Domain) → register / transfer (pending domains, spec §4.2) or renew
 * (live domains, spec §4.4). Replays of the same invoice never queue a second job. InvoicePaid is only
 * dispatched for fully paid invoices, so partial payments never reach this listener.
 */
class HandleInvoicePaid
{
    public function __construct(private Settings $settings, private ActivityLogger $activity) {}

    public function handle(InvoicePaid $event): void
    {
        $byDomain = [];

        foreach ($event->lines as $line) {
            if ($line->billableType === BillableType::Domain && $line->billableId !== null) {
                $byDomain[$line->billableId][] = $line;
            }
        }

        foreach ($byDomain as $domainId => $lines) {
            DB::transaction(fn () => $this->handleDomain((int) $domainId, $lines, $event->invoiceId));
        }
    }

    /**
     * @param  list<InvoiceLineData>  $lines
     */
    private function handleDomain(int $domainId, array $lines, int $invoiceId): void
    {
        $domain = Domain::query()->with('tldModel')->lockForUpdate()->find($domainId);

        if ($domain === null) {
            return;
        }

        $line = collect($lines)->first(fn (InvoiceLineData $line): bool => $line->periodStart !== null) ?? $lines[0];
        $years = $this->years($line, $domain);

        if ($domain->status === DomainStatus::Pending) {
            $this->provision($domain, $invoiceId);

            return;
        }

        if (! in_array($domain->status, [DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption, DomainStatus::Expired], true)) {
            return;
        }

        $record = DomainInvoice::query()->firstOrNew(['domain_id' => $domain->id, 'invoice_id' => $invoiceId]);

        if ($record->exists && $record->status === 'paid') {
            return;
        }

        $record->fill([
            'kind' => $record->kind ?? 'renewal',
            'period_start' => $record->period_start ?? $line->periodStart ?? $domain->next_due_date,
            'years' => $years,
            'status' => 'paid',
        ])->save();

        if (! $this->settings->get('domains.renew_on_payment', true)) {
            $this->activity->log("Renewal invoice paid; renew {$domain->name} manually (renew on payment is off)", $domain, $domain->client_id);

            return;
        }

        $fromExpiry = $domain->expiry_date?->toDateString();
        DB::afterCommit(fn () => RenewDomain::dispatch($domain->id, $years, $fromExpiry, $invoiceId));
    }

    private function provision(Domain $domain, int $invoiceId): void
    {
        if ($domain->provision_invoice_id !== null) {
            return;
        }

        $domain->forceFill(['provision_invoice_id' => $invoiceId])->save();
        DomainInvoice::query()->firstOrCreate(
            ['domain_id' => $domain->id, 'invoice_id' => $invoiceId],
            ['kind' => 'order', 'period_start' => $domain->next_due_date, 'years' => $domain->period_years, 'status' => 'paid'],
        );

        if (! $domain->tldModel->auto_register) {
            $this->activity->log("Staff task: accept the paid {$domain->type} of {$domain->name}", $domain, $domain->client_id);

            return;
        }

        $job = $domain->type === 'transfer' ? new TransferDomain($domain->id) : new RegisterDomain($domain->id);
        DB::afterCommit(fn () => dispatch($job));
    }

    private function years(InvoiceLineData $line, Domain $domain): int
    {
        if ($line->periodStart !== null && $line->periodEnd !== null) {
            return max(1, (int) round($line->periodStart->diffInMonths($line->periodEnd) / 12));
        }

        return $domain->period_years;
    }
}
