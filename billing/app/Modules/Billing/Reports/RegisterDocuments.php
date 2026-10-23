<?php

namespace App\Modules\Billing\Reports;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxNoteLine;
use App\Modules\Billing\Services\FiscalSnapshotWriter;
use App\Support\Currencies\Decimal;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The fiscal documents of a register period (vat-register §6.1): tax invoices by their numbering snapshot and credit
 * and debit notes by their own amounts, never live invoice totals. Each amount is converted to the register currency
 * with the document's stored `report_rate`, rounded half up per document. Drafts, proformas and add-funds invoices
 * have no `numbered_at` and never appear.
 *
 * Rows are in a total order: AD date, invoices before notes, the instant of numbering (so numbers of one sequence
 * stay in order), then kind and id. Both tables are read through indexes on `bs_period` and `issue_date`
 * (invoices: the partial `invoices_register_*` indexes, which need the `numbered_at is not null` predicate).
 */
class RegisterDocuments
{
    private const CHUNK = 500;

    /** @var array<string, RegisterSummary> */
    private array $summaries = [];

    public function __construct(private FiscalSnapshotWriter $snapshots) {}

    /** The register is in rupees unless the tax settings name another reporting currency. */
    public function currency(): string
    {
        return $this->snapshots->reportCurrency() ?? 'NPR';
    }

    public function count(RegisterPeriod $period, bool $notesOnly): int
    {
        return DB::query()->fromSub($this->union($period, $notesOnly), 'docs')->count();
    }

    /**
     * The documents in order, one page when `$limit` is set, else all of them in chunks. Each has its amounts in the
     * register currency (null when the document has no usable rate), its line summary and, for notes, the
     * settlement and the creator.
     *
     * @return Generator<int, RegisterDocument>
     */
    public function documents(RegisterPeriod $period, bool $notesOnly, ?int $limit = null, int $offset = 0, bool $withDetail = true): Generator
    {
        $currency = $this->currency();

        for ($start = $offset, $left = $limit; $left === null || $left > 0; $start += $size) {
            $size = $left === null ? self::CHUNK : min($left, self::CHUNK);
            $rows = DB::query()->fromSub($this->union($period, $notesOnly), 'docs')
                ->orderBy('issue_date')->orderBy('kind_rank')->orderBy('numbered_at')->orderBy('kind')->orderBy('doc_id')
                ->offset($start)->limit($size)->get();

            $details = $withDetail ? $this->details($rows->all()) : [];

            foreach ($rows as $row) {
                yield RegisterDocument::from($row, $currency, $details[$row->kind.':'.$row->doc_id] ?? []);
            }

            if ($rows->count() < $size) {
                return;
            }

            $left = $left === null ? null : $left - $size;
        }
    }

    /**
     * Totals of every document in the period, per kind and per BS month, and the counts the screen warns about.
     * Kept for the request, because the screen and its totals footer read the same period.
     */
    public function summary(RegisterPeriod $period, bool $notesOnly): RegisterSummary
    {
        $key = serialize([$period, $notesOnly, $this->currency()]);

        if (isset($this->summaries[$key])) {
            return $this->summaries[$key];
        }

        $summary = new RegisterSummary($this->currency());

        foreach ($this->documents($period, $notesOnly, withDetail: false) as $document) {
            $summary->add($document);
        }

        return $this->summaries[$key] = $summary;
    }

    /** Minor units converted with a stored rate (a decimal string), half up. */
    public static function convert(int $amount, string $rate): int
    {
        return (int) Decimal::round(Decimal::multiply((string) $amount, $rate), 0);
    }

    private function union(RegisterPeriod $period, bool $notesOnly): Builder
    {
        $notes = $period->scope(TaxNote::query()->toBase(), 'tax_notes')->selectRaw(
            'tax_notes.kind as kind, 1 as kind_rank, tax_notes.id as doc_id, tax_notes.issue_date, tax_notes.issued_at as numbered_at, '
            .'tax_notes.bs_issue_date, tax_notes.number, tax_notes.invoice_number as ref_number, tax_notes.invoice_bs_issue_date as ref_bs_date, '
            .'tax_notes.buyer_snapshot as buyer, tax_notes.currency, tax_notes.report_currency, tax_notes.report_rate, tax_notes.supply_class, '
            .'tax_notes.taxable_amount as taxable, tax_notes.exempt_amount as exempt, tax_notes.tax1_amount + tax_notes.tax2_amount as tax, '
            .'tax_notes.total, tax_notes.reason_code, tax_notes.reason_text, tax_notes.automatic, tax_notes.created_by'
        );

        if ($notesOnly) {
            return $notes;
        }

        return $period->scope(Invoice::query()->toBase(), 'invoices')
            ->whereNotNull('invoices.numbered_at')->whereNotNull('invoices.number')->where('invoices.kind', '<>', 'add_funds')
            ->selectRaw(
                "'invoice' as kind, 0 as kind_rank, invoices.id as doc_id, invoices.issue_date, invoices.numbered_at, "
                .'invoices.bs_issue_date, invoices.number, null as ref_number, null as ref_bs_date, '
                .'invoices.client_snapshot as buyer, invoices.currency, invoices.report_currency, invoices.report_rate, invoices.supply_class, '
                .'coalesce(invoices.fiscal_taxable, 0) as taxable, coalesce(invoices.fiscal_exempt, 0) as exempt, '
                .'coalesce(invoices.fiscal_tax1, 0) + coalesce(invoices.fiscal_tax2, 0) as tax, coalesce(invoices.fiscal_total, 0) as total, '
                .'null as reason_code, null as reason_text, null as automatic, null as created_by'
            )
            ->unionAll($notes);
    }

    /**
     * Line descriptions and quantities of each document, and for notes the settlement and the creator, read for one
     * chunk at a time.
     *
     * @param  list<object>  $rows
     * @return array<string, array{lines: list<array{description: string, quantity: int}>, settlement?: list<string>, creator?: string|null}>
     */
    private function details(array $rows): array
    {
        $ids = ['invoice' => [], 'note' => []];

        foreach ($rows as $row) {
            $ids[$row->kind === 'invoice' ? 'invoice' : 'note'][] = (int) $row->doc_id;
        }

        $details = [];

        foreach (DB::table('invoice_items')->whereIn('invoice_id', $ids['invoice'])->orderBy('sort_order')->orderBy('id')->get(['invoice_id', 'description', 'quantity']) as $item) {
            $details['invoice:'.$item->invoice_id]['lines'][] = ['description' => (string) $item->description, 'quantity' => (int) $item->quantity];
        }

        if ($ids['note'] === []) {
            return $details;
        }

        $kinds = [];

        foreach ($rows as $row) {
            if ($row->kind !== 'invoice') {
                $kinds[(int) $row->doc_id] = $row->kind;
                $details[$row->kind.':'.$row->doc_id] = ['lines' => [], 'settlement' => [], 'creator' => null];
            }
        }

        foreach ($this->noteLines($ids['note']) as $line) {
            $details[$kinds[$line->tax_note_id].':'.$line->tax_note_id]['lines'][] = ['description' => (string) $line->description, 'quantity' => (int) $line->quantity];
        }

        foreach (DB::table('invoice_adjustments')->whereIn('tax_note_id', $ids['note'])->orderBy('id')->get(['tax_note_id', 'reason']) as $adjustment) {
            $details[$kinds[$adjustment->tax_note_id].':'.$adjustment->tax_note_id]['settlement'][] = (string) $adjustment->reason;
        }

        $creators = User::query()->whereIn('id', array_filter(array_map(fn (object $row): ?int => $row->created_by === null ? null : (int) $row->created_by, $rows)))->pluck('name', 'id');

        foreach ($rows as $row) {
            if ($row->kind !== 'invoice' && $row->created_by !== null) {
                $details[$row->kind.':'.$row->doc_id]['creator'] = $creators[(int) $row->created_by] ?? null;
            }
        }

        return $details;
    }

    /**
     * @param  list<int>  $noteIds
     * @return iterable<TaxNoteLine>
     */
    private function noteLines(array $noteIds): iterable
    {
        return TaxNoteLine::query()->whereIn('tax_note_id', $noteIds)->orderBy('sort_order')->orderBy('id')->get(['tax_note_id', 'description', 'quantity']);
    }
}
