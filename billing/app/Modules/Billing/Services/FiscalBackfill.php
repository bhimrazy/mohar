<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Support\FiscalSnapshotCalculator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fills the tax-invoice snapshot of invoices numbered before PR 1a (ADR 0024 §2). Run by the migration and by
 * `billing:fiscal-snapshot`, which also finds invoices numbered by the previous release while a deploy was switching.
 *
 * Safe to run again: it touches only numbered invoices without `numbered_at`, each update repeats that condition, and an
 * issued number is never written. Work is in chunks of invoices in id order, each chunk in its own short transaction
 * that locks only its own invoices (ADR 0021: invoices in id order). The partial index `invoices_fiscal_pending_index`
 * serves the lookup. Add-funds invoices are deposits, not fiscal documents, and are left alone.
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

    private const PAYMENT_TYPES = ['payment', 'manual_income'];

    public function __construct(private FiscalSnapshotCalculator $calculator, private FiscalSnapshotWriter $writer) {}

    /**
     * @param  Closure(int): void|null  $progress  called after each chunk with the number of invoices filled so far
     * @return array{filled: int, reviews: int}
     */
    public function run(int $chunk = self::CHUNK, ?Closure $progress = null): array
    {
        $filled = $reviews = 0;
        $after = 0;

        while (($ids = $this->pending($after, $chunk)) !== []) {
            $after = $ids[array_key_last($ids)];
            [$done, $listed] = DB::transaction(fn (): array => $this->fill($ids));
            $filled += $done;
            $reviews += $listed;

            if ($progress !== null) {
                $progress($filled);
            }
        }

        return ['filled' => $filled, 'reviews' => $reviews];
    }

    /** Numbered invoices (deposits excluded) that have no snapshot yet. */
    public function remaining(): int
    {
        return $this->query()->count();
    }

    /**
     * @return list<int>
     */
    private function pending(int $after, int $limit): array
    {
        return array_values($this->query()->where('id', '>', $after)->orderBy('id')->limit($limit)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    private function query(): Builder
    {
        return DB::table('invoices')->whereNotNull('number')->whereNull('numbered_at')->where('kind', '<>', 'add_funds');
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
            $attributes['numbered_at'] = $numberedAt->format('Y-m-d H:i:s');
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

        return [$estimate, ['reason' => InvoiceFiscalReview::NUMBERING_INSTANT, 'subject' => 'invoice', 'detail' => 'No single payment brought the balance to zero (several payments, credit applied, a line cancelled, a later collections cycle). numbered_at is the invoice\'s paid_at, '.$estimate->format('Y-m-d H:i:s').' UTC.']];
    }

    private function at(mixed $timestamp): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $timestamp, 'UTC');
    }
}
