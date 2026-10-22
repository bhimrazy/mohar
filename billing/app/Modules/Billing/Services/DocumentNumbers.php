<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\BillingSequence;
use App\Modules\Billing\Support\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers (§3). The sequence row is locked FOR UPDATE inside the caller's transaction,
 * so numbers are only consumed when the publishing transaction commits.
 *
 * The row is locked first and created only when missing (the first number of a sequence and period). On PostgreSQL,
 * a concurrent first number waits on the winner's uncommitted row and then locks it, so numbers stay unique and
 * gapless (RenewalPerfReviewProbesTest). Elsewhere this read-first order is weaker, on the first number of a period
 * only: a file-backed SQLite database with concurrent writers (local development) can answer SQLITE_BUSY instead of
 * waiting, and MySQL, which is not supported, would deadlock the two transactions on their gap locks.
 */
class DocumentNumbers
{
    public const INVOICE = 'invoice';

    public const PROFORMA = 'proforma';

    public const QUOTE = 'quote';

    public function __construct(private BillingSettings $settings) {}

    /**
     * @param  CarbonImmutable  $date  the document's company-calendar date (its yearly and monthly period and the
     *                                 {YEAR}, {MONTH} and {DAY} fields); there is no default, so a caller can't fall back to UTC
     */
    public function next(string $sequence, CarbonImmutable $date): string
    {
        $reset = $sequence === self::QUOTE ? 'never' : $this->settings->string('number_reset', 'never');
        $period = match ($reset) {
            'yearly' => $date->format('Y'),
            'monthly' => $date->format('Y-m'),
            default => '',
        };
        $increment = max(1, $this->settings->int('number_increment', 1));

        $number = DB::transaction(function () use ($sequence, $period, $increment): int {
            $sequenceRow = fn () => BillingSequence::query()->where('sequence', $sequence)->where('period', $period)->lockForUpdate();
            // The row exists after the first number of a sequence and period, so lock it first and create it only
            // when missing (a concurrent creator wins quietly; both then lock the same row).
            $row = $sequenceRow()->first();

            if ($row === null) {
                BillingSequence::query()->insertOrIgnore([
                    'sequence' => $sequence, 'period' => $period, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = $sequenceRow()->firstOrFail();
            }

            $number = $row->next_number;
            $row->update(['next_number' => $number + $increment]);

            return $number;
        });

        return $this->format($this->formatFor($sequence), $number, $date);
    }

    public function format(string $format, int $number, CarbonImmutable $date): string
    {
        $padded = str_pad((string) $number, max(0, $this->settings->int('number_padding')), '0', STR_PAD_LEFT);

        return strtr($format, [
            '{NUMBER}' => $padded,
            '{YEAR}' => $date->format('Y'),
            '{MONTH}' => $date->format('m'),
            '{DAY}' => $date->format('d'),
        ]);
    }

    private function formatFor(string $sequence): string
    {
        $format = match ($sequence) {
            self::PROFORMA => $this->settings->string('proforma_format', 'PF-{NUMBER}'),
            self::QUOTE => $this->settings->string('quote_number_format', 'Q-{NUMBER}'),
            default => $this->settings->string('number_format', '{NUMBER}'),
        };

        return str_contains($format, '{NUMBER}') ? $format : $format.'{NUMBER}';
    }
}
