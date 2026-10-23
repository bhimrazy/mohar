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

    /** Business buyers (a company name) without a PAN. */
    public int $withoutPan = 0;

    /** Documents with no stored rate into the register currency, left out of the sums. */
    public int $unconverted = 0;

    public function __construct(public readonly string $currency)
    {
        foreach (array_keys(RegisterDocument::KINDS) as $kind) {
            $this->byKind[$kind] = array_fill_keys(self::FIGURES, 0);
        }
    }

    public function add(RegisterDocument $document): void
    {
        if ($document->business && $document->pan === null) {
            $this->withoutPan++;
        }

        if (! $document->converted()) {
            $this->unconverted++;

            return;
        }

        $figures = [
            'total_sales' => (int) $document->totalSales(),
            'exempt' => (int) $document->exempt,
            'taxable' => (int) $document->taxable,
            'vat' => (int) $document->tax,
            'export_value' => (int) $document->exportValue,
            'total' => (int) $document->totalSales() + (int) $document->tax,
        ];
        $month = $document->bsDate !== null ? substr($document->bsDate, 0, 7) : null;
        $this->byMonth[(string) $month] ??= array_fill_keys(self::FIGURES, 0);

        foreach ($figures as $figure => $amount) {
            $this->byKind[$document->kind][$figure] += $amount;
            $this->byMonth[(string) $month][$figure] += $amount;
        }
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
