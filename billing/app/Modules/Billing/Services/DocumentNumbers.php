<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\BillingSequence;
use App\Modules\Billing\Support\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers (§3). Every sequence has its own format, padding, reset and increment: invoices, proformas
 * and quotes read the shared `number_*` settings, and a credit or debit note reads `<sequence>_format`,
 * `<sequence>_padding` and `<sequence>_reset`. The increment of a note sequence is always 1 (ADR 0024 §1).
 *
 * The sequence row is locked FOR UPDATE inside the caller's transaction, so numbers are only consumed when the
 * publishing transaction commits.
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

    public const CREDIT_NOTE = 'credit_note';

    public const DEBIT_NOTE = 'debit_note';

    /** Sequences of fiscal notes: own settings, increment fixed at 1. */
    private const NOTES = [self::CREDIT_NOTE, self::DEBIT_NOTE];

    public function __construct(private BillingSettings $settings) {}

    /**
     * @param  CarbonImmutable  $date  the document's company-calendar date (its yearly and monthly period and the
     *                                 {YEAR}, {MONTH} and {DAY} fields); there is no default, so a caller can't fall back to UTC
     */
    public function next(string $sequence, CarbonImmutable $date): string
    {
        $period = match ($this->resetFor($sequence)) {
            'yearly' => $date->format('Y'),
            'monthly' => $date->format('Y-m'),
            default => '',
        };
        $increment = $this->incrementFor($sequence);

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

        return $this->format($this->formatFor($sequence), $number, $date, $this->paddingFor($sequence));
    }

    public function format(string $format, int $number, CarbonImmutable $date, ?int $padding = null): string
    {
        $padded = str_pad((string) $number, max(0, $padding ?? $this->settings->int('number_padding')), '0', STR_PAD_LEFT);

        return strtr($format, [
            '{NUMBER}' => $padded,
            '{YEAR}' => $date->format('Y'),
            '{MONTH}' => $date->format('m'),
            '{DAY}' => $date->format('d'),
        ]);
    }

    private function resetFor(string $sequence): string
    {
        $reset = match (true) {
            $sequence === self::QUOTE => 'never',
            in_array($sequence, self::NOTES, true) => $this->settings->string($sequence.'_reset', 'never'),
            default => $this->settings->string('number_reset', 'never'),
        };

        return in_array($reset, ['yearly', 'monthly'], true) ? $reset : 'never';
    }

    private function paddingFor(string $sequence): int
    {
        return in_array($sequence, self::NOTES, true) ? $this->settings->int($sequence.'_padding') : $this->settings->int('number_padding');
    }

    private function incrementFor(string $sequence): int
    {
        return in_array($sequence, self::NOTES, true) ? 1 : max(1, $this->settings->int('number_increment', 1));
    }

    private function formatFor(string $sequence): string
    {
        $format = match ($sequence) {
            self::CREDIT_NOTE => $this->settings->string('credit_note_format', 'CN-{NUMBER}'),
            self::DEBIT_NOTE => $this->settings->string('debit_note_format', 'DN-{NUMBER}'),
            self::PROFORMA => $this->settings->string('proforma_format', 'PF-{NUMBER}'),
            self::QUOTE => $this->settings->string('quote_number_format', 'Q-{NUMBER}'),
            default => $this->settings->string('number_format', '{NUMBER}'),
        };

        return str_contains($format, '{NUMBER}') ? $format : $format.'{NUMBER}';
    }
}
