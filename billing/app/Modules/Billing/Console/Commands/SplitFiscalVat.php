<?php

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Services\FiscalVatSplit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:fiscal-vat-split {--dry-run : Show what would be classified as other tax, write nothing} {--chunk=200 : Invoices per transaction} {--max=5000 : Most invoices to classify in this run (0 = all)}')]
#[Description('Split the tax of invoices numbered before Nepal VAT mode into Nepal VAT and other tax (safe to repeat; run it after choosing the VAT rules, until nothing is left)')]
class SplitFiscalVat extends Command
{
    public function handle(FiscalVatSplit $split): int
    {
        $done = $split->run(max(1, (int) $this->option('chunk')), max(0, (int) $this->option('max')), (bool) $this->option('dry-run'));

        $this->components->info($this->option('dry-run') ? "{$done} invoices would be classified (dry run, nothing written)." : "{$done} invoices classified.");

        if ($split->otherTax() !== []) {
            $this->components->warn('Taxed levels classified as other tax, not Nepal VAT. The flags are final once written: if one of these should be VAT, fix the rules and use --dry-run again before running for real:');
            $this->table(['Tax', 'Invoices'], array_map(fn (string $tax, int $count): array => [$tax, $count], array_keys($split->otherTax()), array_values($split->otherTax())));
        }

        return self::SUCCESS;
    }
}
