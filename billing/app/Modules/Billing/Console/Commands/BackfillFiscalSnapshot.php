<?php

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Services\FiscalBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:fiscal-snapshot {--list : Show the staff-review list instead of filling snapshots} {--all : With --list, include entries staff have seen} {--chunk=200 : Invoices per transaction} {--limit=200 : Most review rows to list}')]
#[Description('Fill the tax-invoice snapshot of numbered invoices that have none (safe to repeat), or list the entries staff must review')]
class BackfillFiscalSnapshot extends Command
{
    /** Run again after a deploy: invoices the previous release numbered meanwhile have no snapshot yet. */
    public function handle(FiscalBackfill $backfill): int
    {
        if ($this->option('list')) {
            return $this->list();
        }

        $result = $backfill->run(max(1, (int) $this->option('chunk')), fn (int $filled) => $this->output->isVerbose() ? $this->line("{$filled} invoices filled") : null);
        $this->components->info("{$result['filled']} invoices filled, {$result['reviews']} review entries written.");

        $left = $backfill->remaining();
        $unseen = InvoiceFiscalReview::query()->unseen()->count();
        if ($unseen > 0) {
            $this->components->warn("{$unseen} entries on the staff-review list are unseen: php artisan billing:fiscal-snapshot --list");
        }

        return $left === 0 ? self::SUCCESS : self::FAILURE;
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
