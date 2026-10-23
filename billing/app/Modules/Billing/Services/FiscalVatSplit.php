<?php

namespace App\Modules\Billing\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Splits the tax of invoices numbered while the Nepal VAT mode was off into Nepal VAT and other tax
 * (`fiscal_tax1_is_vat`, `fiscal_tax2_is_vat`) and sets their supply class from it (ADR 0024, Amendment #432). A level
 * is classified by the tax rule id the invoice stores; only an invoice calculated before rule ids existed (null ids) is
 * matched by name and rate, and it can never be an export. It writes only invoices whose flags are still null.
 *
 * Run by `billing:fiscal-vat-split`, by hand: it needs the mode's rules, which staff choose first. Safe to repeat: it
 * touches only numbered invoices with tax or a tax rule without a classification, and every update repeats that condition. Chunks run in id
 * order, each in its own short transaction that locks only its rows (ADR 0021). Index: `invoices_vat_split_pending_index`.
 */
class FiscalVatSplit
{
    public const CHUNK = 200;

    /** @var array<string, int> "name rate%" => invoices whose taxed level was classified as other tax in this run */
    private array $otherTax = [];

    public function __construct(private NepalVatMode $mode) {}

    /**
     * What this run classified as other tax, so staff see a renamed or missing VAT rule before the flags are final.
     *
     * @return array<string, int>
     */
    public function otherTax(): array
    {
        return $this->otherTax;
    }

    /**
     * `$dryRun` classifies and tallies `otherTax()` but writes nothing.
     *
     * @return int the number of invoices classified; 0 when no Nepal VAT rule is chosen yet
     */
    public function run(int $chunk = self::CHUNK, int $max = 0, bool $dryRun = false): int
    {
        if ($this->mode->vatRules() === []) {
            return 0;
        }

        $done = 0;
        $after = 0;

        while ($max === 0 || $done < $max) {
            $limit = $max === 0 ? $chunk : min($chunk, $max - $done);
            $ids = $this->pending()->where('id', '>', $after)->orderBy('id')->limit($limit)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $ids = array_values($ids);

            if ($ids === []) {
                break;
            }

            $after = $ids[array_key_last($ids)];
            $done += DB::transaction(fn (): int => $this->classify($ids, $dryRun));
        }

        return $done;
    }

    /**
     * @param  list<int>  $ids
     */
    private function classify(array $ids, bool $dryRun): int
    {
        $count = 0;

        foreach ($this->pending()->whereIn('id', $ids)->lockForUpdate()->get(['id', 'supply_class', 'fiscal_tax1', 'fiscal_tax2', 'tax1_name', 'tax1_rate', 'tax2_name', 'tax2_rate', 'tax1_rule_id', 'tax2_rule_id']) as $row) {
            if ($row->fiscal_tax1 === null) {
                continue;
            }

            $vat = $this->mode->classify($row->tax1_rule_id !== null ? (int) $row->tax1_rule_id : null, $row->tax2_rule_id !== null ? (int) $row->tax2_rule_id : null, (int) $row->fiscal_tax1, (int) $row->fiscal_tax2, [$row->tax1_name, (string) $row->tax1_rate, $row->tax2_name, (string) $row->tax2_rate]);

            foreach ([[1, $vat['vat1'], $row->tax1_name, $row->tax1_rate, $row->fiscal_tax1], [2, $vat['vat2'], $row->tax2_name, $row->tax2_rate, $row->fiscal_tax2]] as [, $isVat, $name, $rate, $tax]) {
                if (! $isVat && (int) $tax !== 0) {
                    $key = $name.' '.(float) $rate.'%';
                    $this->otherTax[$key] = ($this->otherTax[$key] ?? 0) + 1;
                }
            }

            $count += $dryRun ? 1 : $this->pending()->where('id', $row->id)->update([
                'fiscal_tax1_is_vat' => $vat['vat1'], 'fiscal_tax2_is_vat' => $vat['vat2'], 'supply_class' => $vat['class'],
            ]);
        }

        return $count;
    }

    private function pending(): Builder
    {
        return DB::table('invoices')->whereNotNull('numbered_at')->whereNull('fiscal_tax1_is_vat')->whereRaw("kind <> 'add_funds'")
            ->where(fn ($query) => $query->where('tax1_amount', '<>', 0)->orWhere('tax2_amount', '<>', 0)->orWhereNotNull('tax1_rule_id')->orWhereNotNull('tax2_rule_id'));
    }
}
