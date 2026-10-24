<?php

namespace App\Modules\Billing\Reports;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxNoteLine;
use App\Modules\Billing\Services\FiscalSnapshotWriter;
use App\Modules\Billing\Services\NepalVatMode;
use App\Support\CalendarDate;
use App\Support\Currencies\Decimal;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

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

    /** Convert in SQL (PostgreSQL) or in PHP; null follows the connection. Tests set it to compare the two paths. */
    public ?bool $convertInSql = null;

    public function __construct(private FiscalSnapshotWriter $snapshots, private NepalVatMode $mode) {}

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
     *
     * Each table is summed on its own through its period index, never through the sorted union. A document whose
     * conversion is the identity (in the register currency, rate 1 or none) is summed in SQL, which is exact for
     * integers. A document converted with another rate is rounded one by one, half away from zero: in SQL on PostgreSQL,
     * so the query count stays fixed; in PHP over lazyById() on SQLite, one more query per 1,000 converted documents
     * (tests/Feature/Billing/VatRegisterTest).
     */
    public function summary(RegisterPeriod $period, bool $notesOnly): RegisterSummary
    {
        $currency = $this->currency();
        $key = serialize([$period, $notesOnly, $currency, $this->convertsInSql()]);

        if (isset($this->summaries[$key])) {
            return $this->summaries[$key];
        }

        $summary = new RegisterSummary($currency);

        foreach ($notesOnly ? ['tax_notes'] : ['invoices', 'tax_notes'] as $table) {
            $this->sum($summary, $table, $period, $currency);
        }

        return $this->summaries[$key] = $summary;
    }

    /**
     * @param  'invoices'|'tax_notes'  $table
     */
    private function sum(RegisterSummary $summary, string $table, RegisterPeriod $period, string $currency): void
    {
        $notes = $table === 'tax_notes';
        $kind = $notes ? 'tax_notes.kind' : "'invoice'";
        $amounts = self::amounts($table);
        $buyer = $notes ? 'tax_notes.buyer_snapshot' : 'invoices.client_snapshot';
        $hasRate = fn (Builder $q): Builder => $q->where("{$table}.report_currency", $currency)->whereNotNull("{$table}.report_rate");
        $noRate = fn (Builder $q): Builder => $q->whereNull("{$table}.report_currency")->orWhere("{$table}.report_currency", '<>', $currency)->orWhereNull("{$table}.report_rate");

        // In the register currency with an identity conversion: exact in SQL.
        $exact = DB::query()->fromSub($this->base($table, $period)->where("{$table}.currency", $currency)
            ->where(fn (Builder $q): Builder => $noRate($q)->orWhere("{$table}.report_rate", 1))
            ->selectRaw("{$kind} as kind, {$table}.bs_period as month, {$table}.supply_class as supply_class, {$amounts}"), 'docs')
            ->selectRaw('kind, month, supply_class, sum(taxable) as taxable, sum(exempt) as exempt, sum(tax) as tax')
            ->groupBy('kind', 'month', 'supply_class')->get();

        foreach ($exact as $group) {
            $sign = $group->kind === 'credit' ? -1 : 1;
            $summary->addAmounts((string) $group->kind, $group->month, $group->supply_class === 'export', $sign * (int) $group->taxable, $sign * (int) $group->exempt, $sign * (int) $group->tax);
        }

        // Converted with a stored rate other than 1: per document, rounded half away from zero, with the credit sign
        // applied first. An export's value is rounded once, as taxable + exempt, the way its row shows it.
        $converted = $this->base($table, $period)->where($hasRate)->where(fn (Builder $q): Builder => $q->where("{$table}.currency", '<>', $currency)->orWhere("{$table}.report_rate", '<>', 1));

        if ($this->convertsInSql()) {
            // PostgreSQL: round() on numeric is half away from zero, as Decimal::round() is.
            $groups = DB::query()->fromSub($converted->selectRaw("{$kind} as kind, {$table}.bs_period as month, {$table}.supply_class as supply_class, {$table}.report_rate as rate, case when {$kind} = 'credit' then -1 else 1 end as sign, {$amounts}"), 'docs')
                ->selectRaw('kind, month, supply_class, '
                    .'cast(sum(round((sign * taxable)::numeric * rate)) as bigint) as taxable, '
                    .'cast(sum(round((sign * exempt)::numeric * rate)) as bigint) as exempt, '
                    .'cast(sum(round((sign * tax)::numeric * rate)) as bigint) as tax, '
                    .'cast(sum(round((sign * (taxable + exempt))::numeric * rate)) as bigint) as sales')
                ->groupBy('kind', 'month', 'supply_class')->get();

            foreach ($groups as $group) {
                $export = $group->supply_class === 'export';
                $summary->addAmounts((string) $group->kind, $group->month, $export, $export ? (int) $group->sales : (int) $group->taxable, $export ? 0 : (int) $group->exempt, (int) $group->tax);
            }
        } else {
            // SQLite (small installs): a decimal column comes back as a float, so convert each document in PHP.
            $converted->selectRaw("{$table}.id, {$kind} as kind, {$table}.bs_period as month, {$table}.supply_class as supply_class, {$table}.report_rate as rate, {$amounts}")
                ->lazyById(1000, "{$table}.id", 'id')
                ->each(function (stdClass $row) use ($summary): void {
                    $rate = RegisterDocument::rate($row->rate);
                    $sign = $row->kind === 'credit' ? -1 : 1;
                    $export = $row->supply_class === 'export';
                    $summary->addAmounts((string) $row->kind, $row->month, $export,
                        self::convert($sign * ($export ? (int) $row->taxable + (int) $row->exempt : (int) $row->taxable), $rate),
                        $export ? 0 : self::convert($sign * (int) $row->exempt, $rate),
                        self::convert($sign * (int) $row->tax, $rate));
                });
        }

        $summary->unconverted += $this->base($table, $period)->where("{$table}.currency", '<>', $currency)->where($noRate)->count();
        $summary->unclassified += $this->unclassified($this->base($table, $period), $table)->count();
        $summary->withoutPan += $this->base($table, $period)->where("{$buyer}->country", 'NP')
            ->whereNotNull("{$buyer}->company")->where("{$buyer}->company", '<>', '')
            ->where(fn (Builder $q): Builder => $q->whereNull("{$buyer}->tax_id")->orWhere("{$buyer}->tax_id", ''))
            ->count();
    }

    /**
     * The register's documents of one table in the period, through its period index.
     *
     * @param  'invoices'|'tax_notes'  $table
     */
    private function base(string $table, RegisterPeriod $period): Builder
    {
        if ($table === 'tax_notes') {
            return $this->sinceMode($period->scope(TaxNote::query()->toBase(), 'tax_notes'), 'tax_notes');
        }

        return $this->sinceMode($period->scope(Invoice::query()->toBase(), 'invoices'), 'invoices')
            ->whereNotNull('invoices.numbered_at')->whereNotNull('invoices.number')->where('invoices.kind', '<>', 'add_funds');
    }

    /**
     * The SQL pieces of one table's rows, built once so the amounts, the flag on each row and the warning count agree.
     * `vat` is Nepal VAT only: the levels flagged as VAT. `unclassified` is true for a document that carries tax but whose
     * levels were never classified (no flags): its amounts are left blank and out of every total, like a document with no
     * rate, and it is counted in a warning (vat-register §6.3). A document of class `exempt` has no Nepal VAT, so its whole
     * value is exempt sales, even where another tax was charged on it (ADR 0024, Amendment #432).
     *
     * @param  'invoices'|'tax_notes'  $table
     * @return array{taxable: literal-string, exempt: literal-string, vat: literal-string, unclassified: literal-string}
     */
    private static function sql(string $table): array
    {
        [$taxable, $exempt, $one, $two, $flag1, $flag2] = $table === 'tax_notes'
            ? ['tax_notes.taxable_amount', 'tax_notes.exempt_amount', 'tax_notes.tax1_amount', 'tax_notes.tax2_amount', 'tax_notes.tax1_is_vat', 'tax_notes.tax2_is_vat']
            : ['coalesce(invoices.fiscal_taxable, 0)', 'coalesce(invoices.fiscal_exempt, 0)', 'coalesce(invoices.fiscal_tax1, 0)', 'coalesce(invoices.fiscal_tax2, 0)', 'invoices.fiscal_tax1_is_vat', 'invoices.fiscal_tax2_is_vat'];

        return [
            'taxable' => "case when {$table}.supply_class = 'exempt' then 0 else {$taxable} end",
            'exempt' => "case when {$table}.supply_class = 'exempt' then {$taxable} + {$exempt} else {$exempt} end",
            'vat' => "(case when {$flag1} then {$one} else 0 end) + (case when {$flag2} then {$two} else 0 end)",
            'unclassified' => "({$flag1} is null and ({$one} + {$two}) <> 0)",
        ];
    }

    /**
     * @param  'invoices'|'tax_notes'  $table
     * @return literal-string
     */
    private static function amounts(string $table): string
    {
        $sql = self::sql($table);

        return "case when {$sql['unclassified']} then 0 else {$sql['taxable']} end as taxable, case when {$sql['unclassified']} then 0 else {$sql['exempt']} end as exempt, case when {$sql['unclassified']} then 0 else {$sql['vat']} end as tax";
    }

    /**
     * @param  'invoices'|'tax_notes'  $table
     */
    private function unclassified(Builder $query, string $table): Builder
    {
        return $query->whereRaw(self::sql($table)['unclassified']);
    }

    /**
     * Only documents dated inside a stretch the mode was on are in the register (vat-register §6.1); with the mode never switched
     * on, none are, because no invoice of that time carries the Nepal VAT flags.
     *
     * @param  'invoices'|'tax_notes'  $table
     */
    private function sinceMode(Builder $query, string $table): Builder
    {
        // Every stretch the mode was on stays listed, after a switch-off and a re-enable too (ADR 0024, Amendment #432); with the
        // mode never switched on there is none.
        $periods = $this->mode->periods();

        if ($periods === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $any) use ($periods, $table): void {
            foreach ($periods as $period) {
                $any->orWhere(function (Builder $one) use ($period, $table): void {
                    $one->where("{$table}.issue_date", '>=', $period['from']);

                    if ($period['to'] !== null) {
                        $one->where("{$table}.issue_date", '<', CalendarDate::parse($period['to'])->addDays(1)->toString());
                    }
                });
            }
        });
    }

    private function convertsInSql(): bool
    {
        return $this->convertInSql ?? DB::connection()->getDriverName() === 'pgsql';
    }

    /** Minor units converted with a stored rate (a decimal string), half up. */
    public static function convert(int $amount, string $rate): int
    {
        return (int) Decimal::round(Decimal::multiply((string) $amount, $rate), 0);
    }

    private function union(RegisterPeriod $period, bool $notesOnly): Builder
    {
        $notes = $this->base('tax_notes', $period)->selectRaw(
            'tax_notes.kind as kind, 1 as kind_rank, tax_notes.id as doc_id, tax_notes.issue_date, tax_notes.issued_at as numbered_at, '
            .'tax_notes.bs_issue_date, tax_notes.number, tax_notes.invoice_number as ref_number, tax_notes.invoice_bs_issue_date as ref_bs_date, '
            .'tax_notes.buyer_snapshot as buyer, tax_notes.currency, tax_notes.report_currency, tax_notes.report_rate, tax_notes.supply_class, '
            .self::amounts('tax_notes').', case when '.self::sql('tax_notes')['unclassified'].' then 1 else 0 end as unclassified, '
            .'tax_notes.total, tax_notes.reason_code, tax_notes.reason_text, tax_notes.automatic, tax_notes.created_by'
        );

        if ($notesOnly) {
            return $notes;
        }

        return $this->base('invoices', $period)
            ->selectRaw(
                "'invoice' as kind, 0 as kind_rank, invoices.id as doc_id, invoices.issue_date, invoices.numbered_at, "
                .'invoices.bs_issue_date, invoices.number, null as ref_number, null as ref_bs_date, '
                .'invoices.client_snapshot as buyer, invoices.currency, invoices.report_currency, invoices.report_rate, invoices.supply_class, '
                .self::amounts('invoices').', case when '.self::sql('invoices')['unclassified'].' then 1 else 0 end as unclassified, coalesce(invoices.fiscal_total, 0) as total, '
                .'null as reason_code, null as reason_text, null as automatic, null as created_by'
            )
            ->unionAll($notes);
    }

    /**
     * Line descriptions and quantities of each document, and for notes the settlement and the creator, read for one
     * chunk at a time.
     *
     * @param  array<int, stdClass>  $rows
     * @return array<string, array{lines?: list<array{description: string, quantity: int}>, settlement?: list<string>, creator?: string|null}>
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

        $creators = User::query()->whereIn('id', array_filter(array_map(fn (stdClass $row): ?int => $row->created_by === null ? null : (int) $row->created_by, $rows)))->pluck('name', 'id');

        foreach ($rows as $row) {
            if ($row->kind !== 'invoice' && $row->created_by !== null) {
                $name = $creators[(int) $row->created_by] ?? null;
                $details[$row->kind.':'.$row->doc_id]['creator'] = is_string($name) ? $name : null;
            }
        }

        return $details;
    }

    /**
     * @param  list<int>  $noteIds
     * @return iterable<stdClass>
     */
    private function noteLines(array $noteIds): iterable
    {
        return TaxNoteLine::query()->whereIn('tax_note_id', $noteIds)->orderBy('sort_order')->orderBy('id')->toBase()->get(['tax_note_id', 'description', 'quantity']);
    }
}
