<?php

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Support\Money;

/**
 * Allocates a credit note to invoice lines (vat-register §5.1). Pure: the caller reads the lines and what earlier notes,
 * pending reservations and classified old rows already used.
 *
 * Each line carries its original value as gross = net + tax1 + tax2, and `used_*` for what is gone. A credited gross is
 * split between net and the two taxes in proportion to the line's own value, so the invoice's snapshotted rates apply
 * and a rule changed later never does. The remainder rule: a note that takes everything a line has left takes exactly its
 * remaining net, tax1 and tax2, so a fully credited invoice nets to zero to the minor unit.
 *
 * @phpstan-type Line array{id: int, description: string, taxable: bool, gross: int, net: int, tax1: int, tax2: int, used_gross: int, used_net: int, used_tax1: int, used_tax2: int}
 * @phpstan-type Part array{item_id: int|null, description: string, taxable: bool, gross: int, net: int, tax1: int, tax2: int}
 */
final class NoteAllocator
{
    /**
     * @param  list<Line>  $lines
     * @param  array<int, int>  $gross  line id => gross to credit
     * @return list<Part>
     */
    public function byLines(array $lines, array $gross, string $currency): array
    {
        $parts = [];

        foreach ($lines as $line) {
            $take = $gross[$line['id']] ?? 0;

            if ($take > 0) {
                $parts[] = $this->part($line, $take, $currency);
            }
        }

        if (count($parts) !== count(array_filter($gross, fn (int $amount): bool => $amount > 0))) {
            throw new BillingException(__('A selected line is not on this invoice.'));
        }

        return $parts;
    }

    /**
     * Spread one gross amount over the lines in proportion to what each has left, half up; the rounding difference goes
     * to the line with the most left.
     *
     * @param  list<Line>  $lines
     * @return list<Part>
     */
    public function byAmount(array $lines, int $amount, string $currency): array
    {
        $left = array_map(fn (array $line): int => $line['gross'] - $line['used_gross'], $lines);
        $total = array_sum($left);

        if ($amount > $total) {
            throw new BillingException(__('At most :amount can still be credited on the lines.', ['amount' => Money::of($total, $currency)->format()]));
        }

        $shares = $this->spread($amount, $left);
        $parts = [];

        foreach ($lines as $i => $line) {
            if ($shares[$i] > 0) {
                $parts[] = $this->part($line, $shares[$i], $currency);
            }
        }

        return $parts;
    }

    /**
     * An amount split in proportion to the weights, rounded half up, the difference to the largest weight.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public function spread(int $amount, array $weights): array
    {
        $sum = array_sum($weights);

        if ($sum === 0 || $weights === []) {
            return array_fill(0, count($weights), 0);
        }

        $shares = array_map(fn (int $weight): int => TaxMath::roundDiv($amount * $weight, $sum), $weights);
        $shares[(int) array_search(max($weights), $weights, true)] += $amount - array_sum($shares);

        return array_values($shares);
    }

    /**
     * @param  Line  $line
     * @return Part
     */
    private function part(array $line, int $gross, string $currency): array
    {
        $left = $line['gross'] - $line['used_gross'];

        if ($gross > $left) {
            throw new BillingException(__('At most :amount can still be credited on :line.', ['amount' => Money::of($left, $currency)->format(), 'line' => $line['description']]));
        }

        $base = ['item_id' => $line['id'], 'description' => $line['description'], 'taxable' => $line['taxable'], 'gross' => $gross];

        if ($gross === $left) {
            return $base + ['net' => $line['net'] - $line['used_net'], 'tax1' => $line['tax1'] - $line['used_tax1'], 'tax2' => $line['tax2'] - $line['used_tax2']];
        }

        $tax1 = min($line['tax1'] - $line['used_tax1'], TaxMath::roundDiv($line['tax1'] * $gross, $line['gross']));
        $tax2 = min($line['tax2'] - $line['used_tax2'], TaxMath::roundDiv($line['tax2'] * $gross, $line['gross']));

        return $base + ['net' => $gross - $tax1 - $tax2, 'tax1' => $tax1, 'tax2' => $tax2];
    }
}
