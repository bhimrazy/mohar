<?php

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\TaxNote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The query behind Billing → Credit notes: the filters, and how each note on a page was settled. The adjustment and
 * transaction lookups use the partial indexes on `tax_note_id`.
 */
class TaxNoteList
{
    public const SETTLEMENTS = ['balance' => 'Invoice balance', 'credit' => 'Account credit', 'refund' => 'Refund to the payer'];

    /**
     * @param  Builder<TaxNote>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<TaxNote>
     */
    public function filtered(Builder $query, array $filters): Builder
    {
        $term = trim((string) ($filters['search'] ?? ''));
        $term = addcslashes($term, '%_\\');

        return $query
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereLike('number', "{$term}%")->orWhereLike('invoice_number', "{$term}%")))
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['reason'] ?? null, fn (Builder $query, string $reason) => $query->where('reason_code', $reason))
            ->when(($filters['automatic'] ?? null) !== null, fn (Builder $query) => $query->where('automatic', $filters['automatic'] === '1'))
            ->when($filters['period'] ?? null, fn (Builder $query, string $period) => $query->where('bs_period', $period))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('issue_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('issue_date', '<=', $to))
            ->when($filters['client'] ?? null, fn (Builder $query, int|string $client) => $query->where('client_id', (int) $client))
            ->when($filters['settlement'] ?? null, fn (Builder $query, string $settlement) => match ($settlement) {
                'refund' => $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('transactions')->whereColumn('transactions.tax_note_id', 'tax_notes.id')),
                'credit' => $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoice_adjustments')->whereColumn('invoice_adjustments.tax_note_id', 'tax_notes.id')->where('reason', 'credit_note_to_credit')),
                default => $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoice_adjustments')->whereColumn('invoice_adjustments.tax_note_id', 'tax_notes.id')->whereIn('reason', ['credit_note', 'debit_note'])),
            });
    }

    /**
     * How each note was settled: against the invoice balance, to account credit, refunded to the payer. A note can be more than one.
     *
     * @param  array<int, int>  $ids
     * @return array<int, list<string>>
     */
    public function settlements(array $ids): array
    {
        $found = array_fill_keys($ids, []);

        foreach (DB::table('invoice_adjustments')->whereIn('tax_note_id', $ids)->get(['tax_note_id', 'reason']) as $row) {
            $kind = $row->reason === 'credit_note_to_credit' ? 'credit' : 'balance';
            $found[(int) $row->tax_note_id][$kind] = $kind;
        }

        foreach (DB::table('transactions')->whereIn('tax_note_id', $ids)->distinct()->pluck('tax_note_id') as $id) {
            $found[(int) $id]['refund'] = 'refund';
        }

        return array_map(fn (array $kinds): array => array_values(array_map(fn (string $kind): string => (string) __(self::SETTLEMENTS[$kind]), $kinds)), $found);
    }
}
