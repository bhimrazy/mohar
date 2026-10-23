<?php

namespace App\Modules\Billing\Reports;

use App\Contracts\Core\CompanyClock;
use App\Contracts\Reports\HasSensitiveExport;
use App\Contracts\Reports\ProvidesCsvOptions;
use App\Contracts\Reports\ProvidesHeaderBlock;
use App\Contracts\Reports\ProvidesTotals;
use App\Contracts\Reports\Report;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ColumnType;
use App\Modules\Billing\Services\SellerDetails;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What the VAT sales register and the credit and debit note register share (vat-register §6, §7): the period
 * filters, the header block, the stored documents and the totals. The export is sensitive (buyer PANs in bulk) and
 * the CSV starts with a BOM so Excel reads Nepali names.
 */
abstract class RegisterReport implements HasSensitiveExport, ProvidesCsvOptions, ProvidesHeaderBlock, ProvidesTotals, Report
{
    public function __construct(
        protected RegisterDocuments $documents,
        protected CompanyClock $clock,
        private SellerDetails $seller,
    ) {}

    /** Whether the register lists notes only. */
    abstract protected function notesOnly(): bool;

    /**
     * @return array<string, scalar|Money|CarbonImmutable|null>
     */
    abstract protected function row(RegisterDocument $document): array;

    /** The column that carries a totals row's label. */
    abstract protected function labelColumn(): string;

    /**
     * The summed figures as this report's money columns show them.
     *
     * @param  array<string, int>  $figures
     * @return array<string, int>
     */
    protected function figures(array $figures): array
    {
        return $figures;
    }

    /** A translated text cell; `__()` is typed to return an array for a group key. */
    protected static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }

    public function category(): string
    {
        return 'billing';
    }

    public function filters(): array
    {
        return RegisterPeriod::filters();
    }

    public function period(ReportQuery $query): RegisterPeriod
    {
        return RegisterPeriod::of($query, $this->clock);
    }

    public function rows(ReportQuery $query): iterable
    {
        foreach ($this->documents->documents($this->period($query), $this->notesOnly(), $query->limit, $query->offset) as $document) {
            yield $this->row($document);
        }
    }

    public function count(ReportQuery $query): int
    {
        return $this->documents->count($this->period($query), $this->notesOnly());
    }

    public function summary(ReportQuery $query): RegisterSummary
    {
        return $this->documents->summary($this->period($query), $this->notesOnly());
    }

    public function csvBom(): bool
    {
        return true;
    }

    public function headerBlock(ReportQuery $query): array
    {
        $seller = $this->seller->snapshot();
        $period = $this->period($query);

        return [
            [__('Seller PAN'), (string) ($seller['pan'] ?? $seller['vat_number'] ?? '')],
            [__('Seller name'), (string) $seller['legal_name']],
            [__('Fiscal year (BS)'), $period->fiscalYear()],
            [__('Tax period'), $period->label()],
        ];
    }

    /**
     * One row per kind (tax invoices, credit notes, debit notes) and the net; an annual view starts with one row per BS
     * month (vat-register §6.3).
     */
    public function totals(ReportQuery $query): array
    {
        $summary = $this->summary($query);
        $rows = [];

        if ($this->period($query)->kind === 'year') {
            foreach ($this->period($query)->monthKeys() as $key => $label) {
                $rows[] = $this->totalsRow($label, $summary->byMonth[$key] ?? array_fill_keys(RegisterSummary::FIGURES, 0), $summary->currency);
            }
        }

        foreach (RegisterDocument::KINDS as $kind => $label) {
            if (! $this->notesOnly() || $kind !== 'invoice') {
                $rows[] = $this->totalsRow(__($label.'s'), $summary->byKind[$kind], $summary->currency);
            }
        }

        $rows[] = $this->totalsRow(__('Net'), $summary->net(), $summary->currency);

        return $rows;
    }

    /**
     * @param  array<string, int>  $figures
     * @return array<string, Money|string>
     */
    private function totalsRow(string $label, array $figures, string $currency): array
    {
        $row = [$this->labelColumn() => $label];
        $keys = array_map(fn (ReportColumn $column): string => $column->key, array_filter($this->columns(new ReportQuery([], null)), fn (ReportColumn $column): bool => $column->type === ColumnType::Money));

        foreach ($keys as $key) {
            $row[$key] = Money::of($this->figures($figures)[$key] ?? 0, $currency);
        }

        return $row;
    }
}
