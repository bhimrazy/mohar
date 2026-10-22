<?php

namespace App\Modules\Billing\Console\Commands;

use App\Contracts\Core\CompanyClock;
use App\Modules\Billing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:report-utc-dates {--limit=500 : Most rows to list}')]
#[Description('List numbered invoices created in the hours when the company date is ahead of the UTC date (read-only)')]
class ReportUtcDates extends Command
{
    private const CHUNK = 500;

    /**
     * Before the company clock (ADR 0013 Amendment 1) documents were dated on the UTC calendar, so a numbered invoice
     * created between 00:00 and 05:45 in Kathmandu can carry the day before. Nothing is rewritten: an issued invoice
     * has been sent and may be filed. The owner and the accountant judge each row from this list.
     */
    public function handle(CompanyClock $clock): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = [];
        $total = 0;

        Invoice::query()->whereNotNull('number')->orderBy('id')
            ->select(['id', 'number', 'created_at', 'issue_date', 'due_date'])
            ->chunkById(self::CHUNK, function ($invoices) use ($clock, $limit, &$rows, &$total): void {
                foreach ($invoices as $invoice) {
                    $created = CarbonImmutable::instance($invoice->created_at)->utc();
                    $companyDate = $clock->localDate($created);

                    if ($companyDate === $created->toDateString()) {
                        continue;
                    }

                    $total++;

                    if (count($rows) < $limit) {
                        $rows[] = [$invoice->number, $created->format('Y-m-d H:i').' UTC', $companyDate, $invoice->issue_date?->toDateString() ?? '-', $invoice->issue_date?->toDateString() === $created->toDateString() ? 'UTC day' : 'other'];
                    }
                }
            });

        if ($total === 0) {
            $this->components->info('No numbered invoice was created while the company date differed from the UTC date ('.$clock->timezone().').');

            return self::SUCCESS;
        }

        $this->table(['Number', 'Created', 'Company date', 'Issue date', 'Issue date is'], $rows);
        $this->components->info($total.' numbered invoice(s) created while the company date differed from the UTC date ('.$clock->timezone().').');
        $this->components->info(count($rows).' listed. Nothing was changed.');

        return self::SUCCESS;
    }
}
