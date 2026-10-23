<?php

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Services\FiscalVatSplit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:fiscal-vat-split {--chunk=200 : Invoices per transaction} {--max=5000 : Most invoices to classify in this run (0 = all)}')]
#[Description('Split the tax of invoices numbered before Nepal VAT mode into Nepal VAT and other tax (safe to repeat; run it after choosing the VAT rules, until nothing is left)')]
class SplitFiscalVat extends Command
{
    public function handle(FiscalVatSplit $split): int
    {
        $done = $split->run(max(1, (int) $this->option('chunk')), max(0, (int) $this->option('max')));

        $this->components->info("{$done} invoices classified.");

        return self::SUCCESS;
    }
}
