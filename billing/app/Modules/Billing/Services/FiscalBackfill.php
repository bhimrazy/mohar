<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Support\FiscalSnapshotCalculator;
use App\Modules\Core\Support\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fills the tax-invoice snapshot of invoices numbered before PR 1a (ADR 0024 §2). Run by the migration and by
 * `billing:fiscal-snapshot`, which also finds invoices numbered by the previous release while a deploy was switching.
 *
 * Safe to run again: it touches only numbered invoices without `numbered_at`, each update repeats that condition, and a
 * number is never written. Chunks run in id order, each in its own short transaction that locks only its invoices
 * (ADR 0021). Index: `invoices_fiscal_pending_index`. Add-funds invoices are deposits and are left alone.
 *
 * `numbered_at` is the first instant at which the number can be shown to exist:
 *  - numbered at publish (no proforma number): the invoice's `created_at`, never later than publishing; every adjustment
 *    on it counts as after numbering;
 *  - numbered on payment: the `created_at` of the one succeeded payment that brought the balance to zero, never its
 *    `occurred_at`, which staff can backdate. When no such payment can be identified the invoice is listed for review and
 *    gets an estimate, its `paid_at`.
 */
class FiscalBackfill
{
    public const CHUNK = 200;

    /** Adjustments one reconcile pass scans. */
    public const RECONCILE_ROWS = 20_000;

    /** Settings key: the last adjustment id the reconcile pass has looked at. */
    private const WATERMARK = 'billing.fiscal_reconcile_after';

    private const PAYMENT_TYPES = ['payment', 'manual_income'];

    public function __construct(private FiscalSnapshotCalculator $calculator, private FiscalSnapshotWriter $writer) {}

    /**
     * Fill the snapshot of up to `$max` pending invoices (0 = all), then reconcile the cached totals.
     *
     * @param  Closure(int): void|null  $progress  called after each chunk with the number of invoices filled so far
     * @return array{filled: int, reviews: int, reconciled: int}
     */
    public function run(int $chunk = self::CHUNK, int $max = 0, ?Closure $progress = null): array
    {
        $filled = $reviews = 0;
        $after = 0;

        while (($max === 0 || $filled < $max) && ($ids = $this->pending($after, $max === 0 ? $chunk : min($chunk, $max - $filled))) !== []) {
            $after = $ids[array_key_last($ids)];
            $this->writer->warm(array_values(DB::table('invoices')->whereIn('id', $ids)->distinct()->pluck('currency')->map(fn (mixed $code): string => (string) $code)->all()));

            try {
                [$done, $listed] = DB::transaction(fn (): array => $this->fill($ids));
            } finally {
                $this->writer->flush();
            }

            $filled += $done;
            $reviews += $listed;

            if ($progress !== null) {
                $progress($filled);
            }
        }

        return ['filled' => $filled, 'reviews' => $reviews, 'reconciled' => $this->reconcile($max === 0 ? self::RECONCILE_ROWS : $max * 4)];
    }

    /** Whether any numbered invoice still has no snapshot. Reads the partial index; the enable check uses it. */
    public function hasPending(): bool
    {
        return $this->query()->exists();
    }

    /**
     * Recompute `credited_total` and `debited_total` from the value adjustments after `numbered_at`, for the invoices
     * that have an adjustment past the watermark, and list each invoice whose cache was wrong. The previous release's
     * `adjust()` knows no caches, so during a blue-green switch or after a rollback it writes value rows the caches miss.
     * Adjustment ids only grow, so a row written later is always past the watermark. Scans at most `$rows` adjustments.
     */
    public function reconcile(int $rows = self::RECONCILE_ROWS): int
    {
        $after = (int) app(Settings::class)->get(self::WATERMARK, 0);
        $scanned = DB::table('invoice_adjustments')->where('id', '>', $after)->orderBy('id')->limit($rows)->get(['id', 'invoice_id']);

        if ($scanned->isEmpty()) {
            return 0;
        }

        $changed = DB::transaction(fn (): int => $this->recompute(array_values($scanned->pluck('invoice_id')->map(fn (mixed $id): int => (int) $id)->unique()->sort()->all())));
        app(Settings::class)->set(self::WATERMARK, (int) $scanned->max('id'));

        return $changed;
    }

    /** Invoices whose cached totals differ from their value rows (a full scan: for the enable check, not a schedule). */
    public function mismatches(): int
    {
        return DB::table('invoices')->whereNotNull('numbered_at')->orderBy('id')->pluck('id')->chunk(self::CHUNK)->sum(
            fn ($ids): int => count($this->drift(array_values($ids->map(fn (mixed $id): int => (int) $id)->all()))),
        );
    }

    /**
     * @param  list<int>  $ids
     */
    private function recompute(array $ids): int
    {
        $drift = $this->drift($ids, lock: true);

        foreach ($drift as $row) {
            DB::table('invoices')->where('id', $row['id'])->update(['credited_total' => $row['credited'], 'debited_total' => $row['debited']]);
            DB::table('invoice_fiscal_reviews')->upsert(
                [['invoice_id' => $row['id'], 'reason' => InvoiceFiscalReview::CACHED_TOTALS, 'subject' => 'invoice', 'detail' => "credited_total {$row['was_credited']} -> {$row['credited']}, debited_total {$row['was_debited']} -> {$row['debited']}", 'seen_at' => null, 'created_at' => now(), 'updated_at' => now()]],
                ['invoice_id', 'reason', 'subject'], ['detail', 'seen_at', 'updated_at'],
            );
        }

        return count($drift);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, credited: int, debited: int, was_credited: int, was_debited: int}>
     */
    private function drift(array $ids, bool $lock = false): array
    {
        $invoices = DB::table('invoices')->whereIn('id', $ids)->whereNotNull('numbered_at')->orderBy('id')->when($lock, fn (Builder $query) => $query->lockForUpdate())->get();
        $rows = DB::table('invoice_adjustments')->whereIn('invoice_id', $invoices->pluck('id'))->whereIn('reason', FiscalSnapshotCalculator::VALUE_REASONS)->get()->groupBy('invoice_id');
        $drift = [];

        foreach ($invoices as $invoice) {
            $at = $this->at($invoice->numbered_at);
            $rowsOf = $rows->get($invoice->id, collect());
            $fixed = [];

            // Timestamps have one-second precision, so a row stamped in the second of numbering may be before or after it:
            // any cache between "strictly after" and "after or at" is accepted, and one outside is clamped to the nearest.
            foreach (['credit' => 'credited_total', 'debit' => 'debited_total'] as $kind => $column) {
                $of = $rowsOf->where('kind', $kind);
                $low = (int) $of->filter(fn (\stdClass $row): bool => $this->at($row->created_at)->gt($at))->sum('amount');
                $high = (int) $of->filter(fn (\stdClass $row): bool => $this->at($row->created_at)->gte($at))->sum('amount');
                $fixed[$kind] = min($high, max($low, (int) $invoice->{$column}));
            }

            if ($fixed['credit'] !== (int) $invoice->credited_total || $fixed['debit'] !== (int) $invoice->debited_total) {
                $drift[] = ['id' => (int) $invoice->id, 'credited' => $fixed['credit'], 'debited' => $fixed['debit'], 'was_credited' => (int) $invoice->credited_total, 'was_debited' => (int) $invoice->debited_total];
            }
        }

        return $drift;
    }

    /**
     * @return list<int>
     */
    private function pending(int $after, int $limit): array
    {
        return array_values($this->query()->where('id', '>', $after)->orderBy('id')->limit($limit)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /** The predicate is written as the partial index writes it, so any plan can use `invoices_fiscal_pending_index`. */
    private function query(): Builder
    {
        return DB::table('invoices')->whereNotNull('number')->whereNull('numbered_at')->whereRaw("kind <> 'add_funds'");
    }

    /**
     * @param  list<int>  $ids
     * @return array{0: int, 1: int}
     */
    private function fill(array $ids): array
    {
        $invoices = DB::table('invoices')->whereIn('id', $ids)->whereNull('numbered_at')->orderBy('id')->lockForUpdate()->get();
        $ids = $invoices->pluck('id')->all();
        $items = DB::table('invoice_items')->whereIn('invoice_id', $ids)->orderBy('id')->get()->groupBy('invoice_id');
        $adjustments = DB::table('invoice_adjustments')->whereIn('invoice_id', $ids)->orderBy('id')->get()->groupBy('invoice_id');
        $transactions = DB::table('transactions')->whereIn('invoice_id', $ids)->orderBy('id')->get()->groupBy('invoice_id');
        $filled = $listed = 0;

        foreach ($invoices as $invoice) {
            $rows = $adjustments->get($invoice->id, collect());
            [$numberedAt, $review] = $this->numberedAt($invoice, $rows, $transactions->get($invoice->id, collect()));
            $values = $rows->filter(fn (\stdClass $row): bool => in_array($row->reason, FiscalSnapshotCalculator::VALUE_REASONS, true));
            $folded = $invoice->proforma_number === null ? collect() : $values->filter(fn (\stdClass $row): bool => $this->at($row->created_at)->lte($numberedAt));
            $later = $values->diffKeys($folded);

            $snapshot = $this->calculator->compute(
                ['total' => (int) $invoice->total, 'subtotal' => (int) $invoice->subtotal, 'tax1_amount' => (int) $invoice->tax1_amount, 'tax2_amount' => (int) $invoice->tax2_amount, 'tax_inclusive' => (bool) $invoice->tax_inclusive],
                $this->writer->itemRows($items->get($invoice->id, collect())),
                $this->writer->adjustmentRows($folded),
                $this->writer->adjustmentRows($later),
            );
            $reviews = $snapshot->reviews;

            if ($review !== null) {
                $reviews[] = $review;
            }

            $attributes = $this->writer->attributes($snapshot, (string) $invoice->currency, $invoice->issue_date !== null ? substr((string) $invoice->issue_date, 0, 10) : null, $numberedAt, $reviews);
            $attributes['credited_total'] = (int) $later->where('kind', 'credit')->sum('amount');
            $attributes['debited_total'] = (int) $later->where('kind', 'debit')->sum('amount');

            if (DB::table('invoices')->where('id', $invoice->id)->whereNull('numbered_at')->update($attributes) === 1) {
                $this->writer->record($invoice->id, $reviews);
                $filled++;
                $listed += count($reviews);
            }
        }

        return [$filled, $listed];
    }

    /**
     * @param  Collection<int, \stdClass>  $adjustments
     * @param  Collection<int, \stdClass>  $transactions
     * @return array{0: CarbonImmutable, 1: array{reason: string, subject: string, detail: string}|null}
     */
    private function numberedAt(\stdClass $invoice, Collection $adjustments, Collection $transactions): array
    {
        if ($invoice->proforma_number === null) {
            return [$this->at($invoice->created_at), null];
        }

        $payments = $transactions->filter(fn (\stdClass $row): bool => in_array($row->type, self::PAYMENT_TYPES, true) && $row->status === 'succeeded' && (int) $row->amount_in > 0)->values();
        $disturbed = $adjustments->contains(fn (\stdClass $row): bool => in_array($row->reason, ['credit_applied', 'credit_removed', 'chargeback'], true))
            || $transactions->contains(fn (\stdClass $row): bool => in_array($row->type, ['chargeback', 'chargeback_reversal'], true));

        if ($payments->count() === 1 && ! $disturbed && $payments->first()->created_at !== null) {
            $payment = $payments->first();
            $paidAt = $this->at($payment->created_at);
            $value = (int) $invoice->total + $adjustments
                ->filter(fn (\stdClass $row): bool => in_array($row->reason, FiscalSnapshotCalculator::VALUE_REASONS, true) && $this->at($row->created_at)->lte($paidAt))
                ->sum(fn (\stdClass $row): int => ($row->kind === 'debit' ? 1 : -1) * (int) $row->amount);

            if ($value - (int) $payment->amount_in <= 0) {
                return [$paidAt, null];
            }
        }

        $estimate = $this->at($invoice->paid_at ?? $invoice->created_at);

        return [$estimate, ['reason' => InvoiceFiscalReview::NUMBERING_INSTANT, 'subject' => 'invoice', 'detail' => 'No single payment brought the balance to zero (several payments, credit applied, a line cancelled, a later collections cycle). numbered_at is the invoice\'s paid_at, '.$estimate->format('Y-m-d H:i:s').' UTC, so the snapshot (not only the date) is an estimate: value rows written between the true numbering and this instant are folded into it.']];
    }

    private function at(mixed $timestamp): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $timestamp, 'UTC');
    }
}
