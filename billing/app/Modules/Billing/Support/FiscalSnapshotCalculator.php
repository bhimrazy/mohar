<?php

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Services\Tax\TaxMath;

/**
 * Computes the tax-invoice value snapshot (ADR 0024 §2) from plain rows, so the numbering path (models) and the
 * backfill (query builder rows) share one rule. Pure: no queries, no clock, nothing recomputed from current tax rules.
 *
 * The value is the invoice's own split (from its lines, with the tax amounts it stored) plus the signed value
 * adjustments written up to numbering, each classified:
 *  - `late_fee`: taxable for its `taxable_amount`, its tax, and the rest exempt;
 *  - `item_cancellation`: classified by the line it cancelled (matched on description and gross amount);
 *  - every other value adjustment (`cancellation`, `refund`, `manual`) stores no split, so it is prorated over the
 *    snapshot and listed for review.
 * A list entry is written only when the invoice carries tax: with no tax every row is exempt and nothing is ambiguous.
 */
final class FiscalSnapshotCalculator
{
    /** The reasons that change the value of the supply (vat-register §4.4); the rest only move money or credit. */
    public const VALUE_REASONS = ['cancellation', 'item_cancellation', 'refund', 'late_fee', 'manual', 'credit_note', 'debit_note'];

    private const DESCRIPTION_LENGTH = 150;

    /**
     * @param  array{total: int, subtotal: int, tax1_amount: int, tax2_amount: int, tax_inclusive: bool}  $invoice
     * @param  list<array{id: int, description: string, amount: int, tax_amount: int, cancelled: bool}>  $items  every line, cancelled ones too
     * @param  list<array{id: int, kind: string, reason: string, amount: int, taxable_amount: int, tax_amount: int, description: string|null}>  $folded  value adjustments up to and including numbering, in id order
     * @param  list<array{id: int, kind: string, reason: string, amount: int, taxable_amount: int, tax_amount: int, description: string|null}>  $after  value adjustments after numbering, listed for review only
     */
    public function compute(array $invoice, array $items, array $folded = [], array $after = []): FiscalSnapshot
    {
        $reviews = [];
        [$taxable, $exempt] = $this->baseSplit($invoice, $items, $reviews);
        $tax1 = $invoice['tax1_amount'];
        $tax2 = $invoice['tax2_amount'];
        $parts = [$taxable, $exempt, $tax1, $tax2];
        $hasTax = $tax1 + $tax2 !== 0;
        $taxedLines = array_filter($items, fn (array $item): bool => $item['tax_amount'] !== 0) !== [];
        $used = [];

        foreach ([...$folded, ...$after] as $index => $row) {
            $classified = $this->classify($row, $invoice, $items, $taxedLines, $hasTax, $used);

            if ($classified['review'] !== null) {
                $reviews[] = $classified['review'];
            }

            if ($index >= count($folded)) {
                continue;
            }

            $sign = $row['kind'] === 'debit' ? 1 : -1;
            $shares = $classified['parts'] ?? $this->prorate($row['amount'], $parts);

            foreach ($shares as $i => $share) {
                $parts[$i] += $sign * $share;
            }
        }

        return new FiscalSnapshot($parts[0], $parts[1], $parts[2], $parts[3], array_sum($parts), $reviews);
    }

    /**
     * Taxable and exempt net of the invoice as published, from its lines. When the lines do not add up to the stored
     * totals the split is estimated from the totals and listed.
     *
     * @param  array{total: int, subtotal: int, tax1_amount: int, tax2_amount: int, tax_inclusive: bool}  $invoice
     * @param  list<array{id: int, description: string, amount: int, tax_amount: int, cancelled: bool}>  $items
     * @param  list<array{reason: string, subject: string, detail: string}>  $reviews
     * @return array{0: int, 1: int}
     */
    private function baseSplit(array $invoice, array $items, array &$reviews): array
    {
        $taxable = $exempt = $lineTax = 0;

        foreach ($items as $item) {
            $net = $invoice['tax_inclusive'] ? $item['amount'] - $item['tax_amount'] : $item['amount'];
            $item['tax_amount'] !== 0 ? $taxable += $net : $exempt += $net;
            $lineTax += $item['tax_amount'];
        }

        $tax = $invoice['tax1_amount'] + $invoice['tax2_amount'];

        if ($items !== [] && $lineTax === $tax && $taxable + $exempt + $tax === $invoice['total']) {
            return [$taxable, $exempt];
        }

        $net = $invoice['total'] - $tax;
        $reviews[] = ['reason' => InvoiceFiscalReview::LINES_MISMATCH, 'subject' => 'invoice', 'detail' => 'The lines do not add up to the invoice totals; the taxable and exempt split is estimated from the totals.'];

        return $tax !== 0 ? [$net, 0] : [0, $net];
    }

    /**
     * @param  array{id: int, kind: string, reason: string, amount: int, taxable_amount: int, tax_amount: int, description: string|null}  $row
     * @param  array{total: int, subtotal: int, tax1_amount: int, tax2_amount: int, tax_inclusive: bool}  $invoice
     * @param  list<array{id: int, description: string, amount: int, tax_amount: int, cancelled: bool}>  $items
     * @param  list<int>  $used  line ids already matched by an earlier cancellation
     * @return array{parts: array{int, int, int, int}|null, review: array{reason: string, subject: string, detail: string}|null}
     */
    private function classify(array $row, array $invoice, array $items, bool $taxedLines, bool $hasTax, array &$used): array
    {
        $subject = 'adjustment:'.$row['id'];

        if ($row['reason'] === 'late_fee') {
            return ['parts' => $this->parts($row, $row['taxable_amount'], $row['tax_amount'], $invoice), 'review' => null];
        }

        if ($row['reason'] !== 'item_cancellation') {
            $review = $hasTax ? ['reason' => InvoiceFiscalReview::LINE_LESS_ROW, 'subject' => $subject, 'detail' => "A {$row['reason']} row stores no line and no tax split, so it counts against the invoice cap only (before numbering, its value is prorated over the snapshot)."] : null;

            return ['parts' => $hasTax ? null : [0, $row['amount'], 0, 0], 'review' => $review];
        }

        $line = $this->matchLine($row, $items, $invoice['tax_inclusive'], $used);

        if ($line !== null) {
            $used[] = $line['id'];

            if ($line['tax_amount'] === 0) {
                // The row stores the net of an untaxed line as `taxable_amount`; the line says it is exempt.
                $review = $taxedLines ? ['reason' => InvoiceFiscalReview::ITEM_CANCELLATION, 'subject' => $subject, 'detail' => 'The cancellation stored its net as taxable on an untaxed line; classified as exempt.'] : null;

                return ['parts' => [0, $row['amount'], 0, 0], 'review' => $review];
            }

            return ['parts' => $this->parts($row, $row['taxable_amount'], $row['tax_amount'], $invoice), 'review' => null];
        }

        if (! $hasTax && ! $taxedLines) {
            return ['parts' => [0, $row['amount'], 0, 0], 'review' => null];
        }

        $review = ['reason' => InvoiceFiscalReview::ITEM_CANCELLATION, 'subject' => $subject, 'detail' => 'The cancellation matches no line of the invoice.'];

        return ['parts' => $row['tax_amount'] !== 0 ? $this->parts($row, $row['taxable_amount'], $row['tax_amount'], $invoice) : null, 'review' => $review];
    }

    /**
     * The cancelled line a row refers to: its description ends with the line's description (the text is translated, the
     * line's description is not) and its gross amount is at least the row's, which is capped at the balance.
     *
     * @param  array{id: int, kind: string, reason: string, amount: int, taxable_amount: int, tax_amount: int, description: string|null}  $row
     * @param  list<array{id: int, description: string, amount: int, tax_amount: int, cancelled: bool}>  $items
     * @param  list<int>  $used
     * @return array{id: int, description: string, amount: int, tax_amount: int, cancelled: bool}|null
     */
    private function matchLine(array $row, array $items, bool $inclusive, array $used): ?array
    {
        $best = null;

        foreach ($items as $item) {
            $gross = abs($inclusive ? $item['amount'] : $item['amount'] + $item['tax_amount']);

            if (! $item['cancelled'] || in_array($item['id'], $used, true) || $gross < $row['amount']
                || ! str_ends_with((string) $row['description'], mb_substr($item['description'], 0, self::DESCRIPTION_LENGTH))) {
                continue;
            }

            if ($gross === $row['amount']) {
                return $item;
            }

            $best ??= $item;
        }

        return $best;
    }

    /**
     * A row's value as [taxable, exempt, tax1, tax2]: the stored taxable part and tax, the rest exempt. The tax is split
     * between the two levels in the proportion of the invoice's own taxes.
     *
     * @param  array{amount: int}  $row
     * @param  array{tax1_amount: int, tax2_amount: int}  $invoice
     * @return array{int, int, int, int}
     */
    private function parts(array $row, int $taxable, int $tax, array $invoice): array
    {
        $levels = $invoice['tax1_amount'] + $invoice['tax2_amount'];
        $tax1 = $levels === 0 ? $tax : TaxMath::roundDiv($tax * $invoice['tax1_amount'], $levels);

        return [$taxable, $row['amount'] - $taxable - $tax, $tax1, $tax - $tax1];
    }

    /**
     * An amount spread over [taxable, exempt, tax1, tax2] in proportion to the current snapshot; the rounding remainder
     * goes to the largest part. With nothing to go by the amount is exempt.
     *
     * @param  array{int, int, int, int}  $parts
     * @return array{int, int, int, int}
     */
    private function prorate(int $amount, array $parts): array
    {
        $weights = [max(0, $parts[0]), max(0, $parts[1]), max(0, $parts[2]), max(0, $parts[3])];
        $sum = $weights[0] + $weights[1] + $weights[2] + $weights[3];

        if ($sum === 0) {
            return [0, $amount, 0, 0];
        }

        $shares = [
            TaxMath::roundDiv($amount * $weights[0], $sum), TaxMath::roundDiv($amount * $weights[1], $sum),
            TaxMath::roundDiv($amount * $weights[2], $sum), TaxMath::roundDiv($amount * $weights[3], $sum),
        ];
        $largest = 0;

        foreach ([1, 2, 3] as $i) {
            if ($weights[$i] > $weights[$largest]) {
                $largest = $i;
            }
        }

        $shares[$largest] += $amount - ($shares[0] + $shares[1] + $shares[2] + $shares[3]);

        return $shares;
    }
}
