<?php

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Services\FiscalBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:fiscal-snapshot {--list : Show the staff-review list instead of filling snapshots} {--all : With --list, include entries staff have seen} {--chunk=200 : Invoices per transaction} {--max=5000 : Most invoices to fill in this run (0 = all)} {--limit=200 : Most review rows to list}')]
#[Description('Fill the tax-invoice snapshot of numbered invoices that have none, reconcile the cached credit/debit totals (safe to repeat), or list the entries staff must review')]
class BackfillFiscalSnapshot extends Command
{
    /** Scheduled every five minutes and run after each deploy switch: the previous release numbers invoices and writes value rows without caches while it drains. */
    public function handle(FiscalBackfill $backfill): int
    {
        if ($this->option('list')) {
            return $this->list();
        }

        $result = $backfill->run(max(1, (int) $this->option('chunk')), max(0, (int) $this->option('max')), fn (int $filled) => $this->output->isVerbose() ? $this->line("{$filled} invoices filled") : null);
        $this->components->info("{$result['filled']} invoices filled, {$result['reviews']} review entries written, {$result['reconciled']} invoices' cached totals corrected.");

        if ($backfill->hasPending()) {
            $this->components->warn('Invoices are still waiting for a snapshot; the next scheduled run continues.');
        }

        $unseen = InvoiceFiscalReview::query()->unseen()->count();

        if ($unseen > 0) {
            $this->components->warn("{$unseen} entries on the staff-review list are unseen: php artisan billing:fiscal-snapshot --list");
        }

        return self::SUCCESS;
    }

    private function list(): int
    {
        $query = InvoiceFiscalReview::query()->with('invoice:id,number,proforma_number')->orderBy('invoice_id')->orderBy('id');
        if (! $this->option('all')) {
            $query->unseen();
        }

        $total = (clone $query)->count();
        $rows = $query->limit(max(1, (int) $this->option('limit')))->get();

        $this->table(['Invoice', 'Reason', 'Subject', 'Detail'], $rows->map(fn (InvoiceFiscalReview $row): array => [
            $row->invoice->number ?? $row->invoice->proforma_number ?? '#'.$row->invoice_id, $row->reason, $row->subject, $row->detail,
        ])->all());
        $this->line($total > $rows->count() ? "Showing {$rows->count()} of {$total}." : "{$total} entries.");

        return self::SUCCESS;
    }
}
