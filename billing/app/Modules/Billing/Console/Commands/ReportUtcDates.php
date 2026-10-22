<?php

namespace App\Modules\Billing\Console\Commands;

use App\Contracts\Core\CompanyClock;
use App\Modules\Billing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:report-utc-dates {--limit=500 : Most rows to list}')]
#[Description('List numbered invoices created, paid or updated while the company date differed from the UTC date (read-only)')]
class ReportUtcDates extends Command
{
    private const CHUNK = 500;

    /**
     * Before the company clock (ADR 0013 Amendment 1) documents were dated on the UTC calendar, so a numbered invoice
     * dated or numbered between 00:00 and 05:45 in Kathmandu can carry the day before. The moment an invoice was
     * dated is not stored, so three instants stand for it: creation (an invoice issued directly), payment (numbered on
     * payment) and the last update (a draft published later is saved at that moment). A row is listed when any of
     * them fell where the company date differs from the UTC date; the "Because" column says which. Nothing is
     * rewritten: an issued invoice has been sent and may be filed. The owner and the accountant judge each row.
     */
    public function handle(CompanyClock $clock): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = [];
        $total = 0;

        Invoice::query()->whereNotNull('number')->orderBy('id')
            ->select(['id', 'number', 'created_at', 'paid_at', 'updated_at', 'issue_date'])
            ->chunkById(self::CHUNK, function ($invoices) use ($clock, $limit, &$rows, &$total): void {
                foreach ($invoices as $invoice) {
                    $because = [];

                    foreach (['created_at' => 'created', 'paid_at' => 'paid', 'updated_at' => 'updated'] as $column => $label) {
                        $at = $invoice->{$column};

                        if ($at !== null && $clock->localDate($at) !== CarbonImmutable::instance($at)->utc()->toDateString()) {
                            $because[] = $label.' '.CarbonImmutable::instance($at)->utc()->format('Y-m-d H:i').' UTC';
                        }
                    }

                    if ($because === []) {
                        continue;
                    }

                    $total++;

                    if (count($rows) < $limit) {
                        $rows[] = [$invoice->number, $invoice->issue_date?->toDateString() ?? '-', implode('; ', $because)];
                    }
                }
            });

        if ($total === 0) {
            $this->components->info('No numbered invoice was created, paid or updated while the company date differed from the UTC date; company zone '.$clock->timezone().'.');

            return self::SUCCESS;
        }

        $this->table(['Number', 'Issue date', 'Because'], $rows);
        $this->components->info($total.' numbered invoice(s) were created, paid or updated while the company date differed from the UTC date; company zone '.$clock->timezone().'.');
        $this->components->info(count($rows).' listed. Nothing was changed.');

        return self::SUCCESS;
    }
}
