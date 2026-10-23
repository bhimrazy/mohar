<?php

namespace App\Modules\Billing\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Splits the tax of invoices numbered before the Nepal VAT classification existed into Nepal VAT and other tax
 * (`fiscal_tax1_is_vat`, `fiscal_tax2_is_vat`) and sets their supply class from it. Those invoices have no tax rule ids,
 * so a level is Nepal VAT when its name and rate equal those of a rule chosen as Nepal VAT; none can be an export.
 *
 * Run by `billing:fiscal-vat-split`, by hand: it needs the mode's rules, which staff choose first. Safe to repeat: it
 * touches only taxed, numbered invoices without a classification, and every update repeats that condition. Chunks run in id
 * order, each in its own short transaction that locks only its rows (ADR 0021). Index: `invoices_vat_split_pending_index`.
 */
class FiscalVatSplit
{
    public const CHUNK = 200;

    public function __construct(private NepalVatMode $mode) {}

    /**
     * @return int the number of invoices classified; 0 when no Nepal VAT rule is chosen yet
     */
    public function run(int $chunk = self::CHUNK, int $max = 0): int
    {
        if ($this->mode->vatRules() === []) {
            return 0;
        }

        $done = 0;
        $after = 0;

        while ($max === 0 || $done < $max) {
            $limit = $max === 0 ? $chunk : min($chunk, $max - $done);
            $ids = $this->pending()->where('id', '>', $after)->orderBy('id')->limit($limit)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            if ($ids === []) {
                break;
            }

            $after = $ids[array_key_last($ids)];
            $done += DB::transaction(fn (): int => $this->classify($ids));
        }

        return $done;
    }

    /**
     * @param  list<int>  $ids
     */
    private function classify(array $ids): int
    {
        $count = 0;

        foreach ($this->pending()->whereIn('id', $ids)->lockForUpdate()->get(['id', 'supply_class', 'fiscal_tax1', 'fiscal_tax2', 'tax1_name', 'tax1_rate', 'tax2_name', 'tax2_rate']) as $row) {
            if ($row->fiscal_tax1 === null) {
                continue;
            }

            $vat = $this->mode->classify(null, null, (int) $row->fiscal_tax1, (int) $row->fiscal_tax2, [$row->tax1_name, (string) $row->tax1_rate, $row->tax2_name, (string) $row->tax2_rate]);
            $count += $this->pending()->where('id', $row->id)->update([
                'fiscal_tax1_is_vat' => $vat['vat1'], 'fiscal_tax2_is_vat' => $vat['vat2'], 'supply_class' => $vat['class'],
            ]);
        }

        return $count;
    }

    private function pending(): Builder
    {
        return DB::table('invoices')->whereNotNull('numbered_at')->whereNull('fiscal_tax1_is_vat')->whereRaw("kind <> 'add_funds'")
            ->where(fn ($query) => $query->where('tax1_amount', '<>', 0)->orWhere('tax2_amount', '<>', 0));
    }
}
