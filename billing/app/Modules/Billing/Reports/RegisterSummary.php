<?php

namespace App\Modules\Billing\Reports;

/**
 * Sums of a register period in the register currency, in minor units: per document kind, per BS month, and the
 * documents the screen warns about (vat-register §6.3). Net VAT is the invoice VAT minus credit-note VAT plus
 * debit-note VAT, exactly, because every amount is a signed snapshot or note value.
 */
final class RegisterSummary
{
    public const FIGURES = ['total_sales', 'exempt', 'taxable', 'vat', 'export_value', 'total'];

    /** @var array<string, array<string, int>> kind => figure => sum */
    public array $byKind = [];

    /** @var array<string, array<string, int>> BS period key => figure => sum */
    public array $byMonth = [];

    /** Nepal business buyers (a company name, country NP) without a PAN. */
    public int $withoutPan = 0;

    /** Documents with no stored rate into the register currency, left out of the sums. */
    public int $unconverted = 0;

    /** Documents that carry tax but whose tax levels were never classified: their tax is left out of VAT. */
    public int $unclassified = 0;

    public function __construct(public readonly string $currency)
    {
        foreach (array_keys(RegisterDocument::KINDS) as $kind) {
            $this->byKind[$kind] = array_fill_keys(self::FIGURES, 0);
        }
    }

    /**
     * Add one document or one group of documents, already signed and in the register currency. An export's value is
     * export value, not taxable or exempt sales.
     */
    public function addAmounts(string $kind, ?string $month, bool $export, int $taxable, int $exempt, int $tax): void
    {
        $figures = [
            'total_sales' => $taxable + $exempt,
            'exempt' => $export ? 0 : $exempt,
            'taxable' => $export ? 0 : $taxable,
            'vat' => $tax,
            'export_value' => $export ? $taxable + $exempt : 0,
            'total' => $taxable + $exempt + $tax,
        ];
        $this->byMonth[(string) $month] ??= array_fill_keys(self::FIGURES, 0);

        foreach ($figures as $figure => $amount) {
            $this->byKind[$kind][$figure] += $amount;
            $this->byMonth[(string) $month][$figure] += $amount;
        }
    }

    /** Whether any document in the period has export value: the screen shows the export columns only then. */
    public function hasExport(): bool
    {
        return collect($this->byKind)->contains(fn (array $figures): bool => $figures['export_value'] !== 0);
    }

    /** @return array<string, int> figure => net sum over every kind */
    public function net(): array
    {
        $net = array_fill_keys(self::FIGURES, 0);

        foreach ($this->byKind as $figures) {
            foreach ($figures as $figure => $amount) {
                $net[$figure] += $amount;
            }
        }

        return $net;
    }
}
