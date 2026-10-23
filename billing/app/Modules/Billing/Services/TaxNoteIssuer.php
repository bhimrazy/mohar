<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Core\CompanyClock;
use App\Events\Billing\CreditNoteIssued;
use App\Events\Billing\DebitNoteIssued;
use App\Events\Billing\InvoiceCancelled;
use App\Events\Billing\InvoiceModified;
use App\Modules\Billing\Data\NotePreview;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Enums\AdjustmentKind;
use App\Modules\Billing\Enums\AdjustmentReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Exceptions\StaleNoteException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxNoteLine;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Modules\Billing\Services\Tax\TaxRates;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BikramSambat;
use App\Modules\Billing\Support\BikramSambatDate;
use App\Modules\Billing\Support\NoteAllocator;
use App\Modules\Core\Models\Client;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use OutOfRangeException;

/**
 * Issues credit and debit notes against a numbered invoice (ADR 0024, vat-register §5.1). Confirm-then-apply (ADR 0021):
 * preview() plans a note, issue() takes the invoice lock, plans it again and refuses when the invoice version or the
 * plan's digest differs from the preview's.
 *
 * Lock order (ADR 0024 §4): payments, then the invoice, then the client, then the invoice's pending reservations, then the
 * sequence row, taken last. This slice settles to the balance only, so it takes no payment lock and posts no credit (it
 * locks the client for the order's sake); a note above the balance is refused until settlement to account credit or refund
 * exists.
 *
 * Not yet handled, and refused with a message: invoices with discount lines (they are credited with their parent line).
 *
 * @phpstan-import-type Line from NoteAllocator
 * @phpstan-import-type Part from NoteAllocator
 */
class TaxNoteIssuer
{
    public const CREDIT_REASONS = ['invoice_cancelled', 'lines_cancelled', 'refunded', 'refunded_at_provider', 'service_not_delivered', 'price_correction', 'duplicate_invoice', 'discount_after_sale', 'goodwill', 'other'];

    public const DEBIT_REASONS = ['late_fee', 'price_correction', 'reverses_credit_note', 'other'];

    public function __construct(
        private NoteAllocator $allocator,
        private InvoiceSettlement $settlement,
        private DocumentNumbers $numbers,
        private CompanyClock $clock,
    ) {}

    public function preview(NoteRequest $request): NotePreview
    {
        return $this->plan($request, Invoice::query()->findOrFail($request->invoiceId));
    }

    /**
     * @throws StaleNoteException when the invoice or the plan changed since the preview
     * @throws BillingException when a rule or a cap is violated
     */
    public function issue(NoteRequest $request, int $version, string $digest): TaxNote
    {
        return DB::transaction(function () use ($request, $version, $digest): TaxNote {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($request->invoiceId);
            // The client next: the note's own insert takes a key-share lock on it, and the refund paths that hold the client
            // for update then wait for the sequence. Locking it here keeps both in the same order.
            Client::query()->whereKey($invoice->client_id)->lockForUpdate()->firstOrFail();
            DB::table('tax_note_reservations')->where('invoice_id', $invoice->id)->where('status', 'pending')->orderBy('id')->lockForUpdate()->pluck('id');
            $plan = $this->plan($request, $invoice);

            if ($invoice->version !== $version || $plan->digest !== $digest) {
                throw StaleNoteException::make();
            }

            $at = $this->clock->now()->utc();
            $date = $this->clock->localDate($at);
            $bs = $this->bsDate($date);
            $credit = $request->kind === 'credit';
            $adjustment = $this->settlement->adjust(
                $invoice, $credit ? AdjustmentKind::Credit : AdjustmentKind::Debit, $credit ? AdjustmentReason::CreditNote : AdjustmentReason::DebitNote,
                $plan->total, mb_substr($request->reasonText, 0, 255), userId: $request->userId, taxableAmount: $plan->taxable, taxAmount: $plan->tax1 + $plan->tax2,
            );
            // The sequence row is locked last, just before the note is inserted.
            $number = $this->numbers->next($credit ? DocumentNumbers::CREDIT_NOTE : DocumentNumbers::DEBIT_NOTE, CarbonImmutable::parse($date, 'UTC'));

            $note = (new TaxNote)->forceFill([
                'kind' => $request->kind, 'number' => $number, 'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id, 'currency' => $invoice->currency,
                'issue_date' => $date, 'issued_at' => $at, 'invoice_number' => (string) $invoice->number, 'invoice_bs_issue_date' => $invoice->bs_issue_date,
                'reason_code' => $request->reasonCode, 'reason_text' => $request->reasonText, 'taxable_amount' => $plan->taxable, 'exempt_amount' => $plan->exempt,
                'tax1_amount' => $plan->tax1, 'tax2_amount' => $plan->tax2, 'total' => $plan->total, 'report_currency' => $invoice->report_currency,
                'report_rate' => $invoice->report_rate, 'supply_class' => $invoice->supply_class, 'seller_snapshot' => $invoice->seller_snapshot,
                'buyer_snapshot' => $invoice->client_snapshot, 'bs_issue_date' => $bs->toIso(), 'bs_period' => BikramSambat::period($bs),
                'bs_fiscal_year' => BikramSambat::fiscalYear($bs), 'automatic' => $request->automatic, 'created_by' => $request->userId,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $note->save();

            foreach ($plan->parts as $i => $part) {
                TaxNoteLine::query()->create([
                    'tax_note_id' => $note->id, 'invoice_item_id' => $part['item_id'], 'description' => mb_substr($part['description'], 0, 255), 'quantity' => 1,
                    'unit_amount' => $part['net'], 'amount' => $part['net'], 'taxable' => $part['taxable'], 'tax1_amount' => $part['tax1'], 'tax2_amount' => $part['tax2'], 'sort_order' => $i,
                ]);
            }

            // A note aimed at one line names it on its adjustment.
            $adjustment->forceFill(['tax_note_id' => $note->id, 'invoice_item_id' => count($plan->parts) === 1 ? $plan->parts[0]['item_id'] : null])->save();
            $this->afterNote($invoice, $credit);
            event($credit
                ? new CreditNoteIssued($note->id, $number, $invoice->id, $invoice->client_id, $plan->total, $invoice->currency, $request->automatic)
                : new DebitNoteIssued($note->id, $number, $invoice->id, $invoice->client_id, $plan->total, $invoice->currency, $request->automatic));

            return $note;
        });
    }

    private function plan(NoteRequest $request, Invoice $invoice): NotePreview
    {
        $this->guard($request, $invoice);
        $credit = $request->kind === 'credit';
        $parts = $credit ? $this->creditParts($request, $invoice) : $this->debitParts($request, $invoice);
        $total = array_sum(array_column($parts, 'gross'));
        $balanceAfter = $invoice->balance + ($credit ? -$total : $total);
        $money = fn (int $amount): string => Money::of($amount, $invoice->currency)->format();

        if ($total <= 0) {
            throw new BillingException(__('A note needs an amount above zero.'));
        }

        if ($credit) {
            $available = $invoice->fiscal_total + $invoice->debited_total - $invoice->credited_total - $this->pending($invoice)['gross'];

            if ($total > $available) {
                throw new BillingException(__('At most :amount can still be credited on this invoice.', ['amount' => $money(max(0, $available))]));
            }

            if ($balanceAfter < 0) {
                throw new BillingException(__('A credit note above the open balance of :amount needs a settlement to account credit or a refund, which is not available yet.', ['amount' => $money($invoice->balance)]));
            }
        }

        $net = fn (bool $taxable): int => array_sum(array_map(fn (array $part): int => $part['net'], array_filter($parts, fn (array $part): bool => $part['taxable'] === $taxable)));
        $tax1 = array_sum(array_column($parts, 'tax1'));
        $tax2 = array_sum(array_column($parts, 'tax2'));

        return new NotePreview(
            $request->kind, $parts, $total, $net(true), $net(false), $tax1, $tax2, $balanceAfter, $invoice->version,
            hash('sha256', (string) json_encode([$request->kind, $invoice->id, $request->reasonCode, $total, $parts])),
        );
    }

    private function guard(NoteRequest $request, Invoice $invoice): void
    {
        if (! in_array($request->reasonCode, $request->kind === 'credit' ? self::CREDIT_REASONS : self::DEBIT_REASONS, true)) {
            throw new BillingException(__('Choose a reason for the note.'));
        }

        if (trim($request->reasonText) === '' || mb_strlen($request->reasonText) > 500) {
            throw new BillingException(__('The reason shown to the client is required and at most 500 characters.'));
        }

        if ($invoice->kind === InvoiceKind::AddFunds || $invoice->numbered_at === null || $invoice->fiscal_total === null) {
            throw new BillingException(__('Only a numbered tax invoice with a stored snapshot takes a note.'));
        }

        if ($this->clock->isFallback()) {
            throw new BillingException(__('The company time zone is not valid; fix it before issuing a tax document.'));
        }

        if ($request->kind === 'debit' && ! $invoice->status->isPayable()) {
            throw new BillingException(__('A debit note can only be issued on an unpaid invoice for now.'));
        }

        // ADR 0024 §3: a debit note adds at most the invoice's original value, which also bounds the tax arithmetic.
        if ($request->kind === 'debit' && (int) $request->amount > $invoice->fiscal_total) {
            throw new BillingException(__('A debit note can add at most :amount, the value of the invoice.', ['amount' => Money::of($invoice->fiscal_total, $invoice->currency)->format()]));
        }

        $this->bsDate($this->clock->localDate($this->clock->now()));
    }

    private function bsDate(string $date): BikramSambatDate
    {
        try {
            return BikramSambat::fromAd(CarbonImmutable::parse($date, 'UTC'));
        } catch (OutOfRangeException) {
            throw new BillingException(__('The date is outside the Bikram Sambat table; no tax document can be numbered.'));
        }
    }

    /**
     * @return list<Part>
     */
    private function creditParts(NoteRequest $request, Invoice $invoice): array
    {
        $lines = $this->lines($invoice);

        return $request->lines !== null
            ? $this->allocator->byLines($lines, $request->lines, $invoice->currency)
            : $this->allocator->byAmount($lines, (int) $request->amount, $invoice->currency);
    }

    /**
     * @return list<Part>
     */
    private function debitParts(NoteRequest $request, Invoice $invoice): array
    {
        $gross = (int) $request->amount;
        $rates = new TaxRates($invoice->tax1_name, TaxRates::toMilli($invoice->tax1_rate), $invoice->tax2_name, TaxRates::toMilli($invoice->tax2_rate), $invoice->tax_compound, inclusive: true);
        [$net, $tax1, $tax2] = $request->taxable && $rates->hasTax() ? TaxMath::split($gross, $rates) : [$gross, 0, 0];

        return [['item_id' => null, 'description' => $request->reasonText, 'taxable' => $request->taxable, 'gross' => $gross, 'net' => $net, 'tax1' => $tax1, 'tax2' => $tax2]];
    }

    /**
     * The creditable lines with what earlier notes, pending reservations and classified old item cancellations used.
     *
     * @return list<Line>
     */
    private function lines(Invoice $invoice): array
    {
        $items = DB::table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')->get();

        if ($items->contains(fn (\stdClass $item): bool => (int) $item->amount < 0)) {
            throw new BillingException(__('Notes on an invoice with discount lines are not available yet.'));
        }

        $items = $items->values();
        $weights = array_values($items->map(fn (\stdClass $item): int => (int) $item->tax_amount)->all());
        $tax = array_sum($weights);

        if ($tax !== $invoice->tax1_amount + $invoice->tax2_amount || $items->sum('amount') + ($invoice->tax_inclusive ? 0 : $tax) !== $invoice->total) {
            throw new BillingException(__('The lines of this invoice do not add up to its totals; see the fiscal review list.'));
        }

        $tax1 = $this->allocator->spread($invoice->tax1_amount, $weights);
        $lines = [];

        foreach ($items->values() as $i => $item) {
            $net = (int) $item->amount - ($invoice->tax_inclusive ? (int) $item->tax_amount : 0);
            $lines[(int) $item->id] = [
                'id' => (int) $item->id, 'description' => (string) $item->description, 'taxable' => (bool) $item->taxable && $tax !== 0, 'gross' => $net + (int) $item->tax_amount, 'net' => $net,
                'tax1' => $tax1[$i], 'tax2' => (int) $item->tax_amount - $tax1[$i], 'used_gross' => 0, 'used_net' => 0, 'used_tax1' => 0, 'used_tax2' => 0,
            ];
        }

        foreach ([...$this->usedByNotes($invoice), ...$this->pending($invoice)['lines'], ...$this->usedByOldRows($invoice, $items->all(), $lines)] as $used) {
            if (isset($lines[$used['item']])) {
                $line = &$lines[$used['item']];
                $line['used_net'] += $used['net'];
                $line['used_tax1'] += $used['tax1'];
                $line['used_tax2'] += $used['tax2'];
                $line['used_gross'] += $used['net'] + $used['tax1'] + $used['tax2'];
                unset($line);
            }
        }

        return array_values(array_filter($lines, fn (array $line): bool => $line['gross'] > 0));
    }

    /**
     * @return array<int, array{item: int, net: int, tax1: int, tax2: int}>
     */
    private function usedByNotes(Invoice $invoice): array
    {
        return TaxNoteLine::query()->join('tax_notes as n', 'n.id', '=', 'tax_note_lines.tax_note_id')
            ->where('n.invoice_id', $invoice->id)->where('n.kind', 'credit')->whereNotNull('tax_note_lines.invoice_item_id')->groupBy('tax_note_lines.invoice_item_id')
            ->selectRaw('tax_note_lines.invoice_item_id as item, sum(tax_note_lines.amount) as net, sum(tax_note_lines.tax1_amount) as tax1, sum(tax_note_lines.tax2_amount) as tax2')->toBase()->get()
            ->map(fn (\stdClass $row): array => ['item' => (int) $row->item, 'net' => (int) $row->net, 'tax1' => (int) $row->tax1, 'tax2' => (int) $row->tax2])->values()->all();
    }

    /**
     * Pending refund reservations count in the line caps and the invoice cap (ADR 0024 §5).
     *
     * @return array{gross: int, lines: array<int, array{item: int, net: int, tax1: int, tax2: int}>}
     */
    private function pending(Invoice $invoice): array
    {
        $rows = DB::table('tax_note_reservation_lines as l')->join('tax_note_reservations as r', 'r.id', '=', 'l.reservation_id')
            ->where('r.invoice_id', $invoice->id)->where('r.status', 'pending')->get(['l.invoice_item_id', 'l.amount', 'l.tax1_amount', 'l.tax2_amount']);

        return [
            'gross' => (int) $rows->sum(fn (\stdClass $row): int => (int) $row->amount + (int) $row->tax1_amount + (int) $row->tax2_amount),
            'lines' => $rows->filter(fn (\stdClass $row): bool => $row->invoice_item_id !== null)
                ->map(fn (\stdClass $row): array => ['item' => (int) $row->invoice_item_id, 'net' => (int) $row->amount, 'tax1' => (int) $row->tax1_amount, 'tax2' => (int) $row->tax2_amount])->values()->all(),
        ];
    }

    /**
     * Old-style `item_cancellation` rows count against the line they cancelled, found by the exact text the old path wrote
     * ("Cancelled line: <description>", in this language or in English). Rows that match no line count against the
     * invoice cap only.
     *
     * @param  array<int, \stdClass>  $items
     * @param  array<int, Line>  $lines
     * @return array<int, array{item: int, net: int, tax1: int, tax2: int}>
     */
    private function usedByOldRows(Invoice $invoice, array $items, array $lines): array
    {
        $rows = DB::table('invoice_adjustments')->where('invoice_id', $invoice->id)->where('reason', 'item_cancellation')->where('kind', 'credit')->orderBy('id')->get();
        $taken = [];
        $used = [];

        foreach ($rows as $row) {
            $item = collect($items)->first(function (\stdClass $item) use ($row, $taken, $lines): bool {
                $text = mb_substr((string) $item->description, 0, 150);

                return $item->cancelled_at !== null && ! in_array((int) $item->id, $taken, true) && isset($lines[(int) $item->id])
                    && in_array((string) $row->description, [__('Cancelled line: :line', ['line' => $text]), 'Cancelled line: '.$text], true)
                    && $lines[(int) $item->id]['gross'] >= (int) $row->amount;
            });

            if ($item === null) {
                continue;
            }

            $taken[] = (int) $item->id;
            $line = $lines[(int) $item->id];
            $tax = $line['tax1'] + $line['tax2'] === 0 ? 0 : min((int) $row->tax_amount, $line['tax1'] + $line['tax2']);
            $tax1 = $line['tax1'] + $line['tax2'] === 0 ? 0 : TaxMath::roundDiv($tax * $line['tax1'], $line['tax1'] + $line['tax2']);
            $used[] = ['item' => (int) $item->id, 'net' => (int) $row->amount - $tax, 'tax1' => $tax1, 'tax2' => $tax - $tax1];
        }

        return $used;
    }

    /**
     * A credit note that brings the balance to zero settles the invoice (paid), or cancels it when nothing was paid.
     */
    private function afterNote(Invoice $invoice, bool $credit): void
    {
        if (! $credit || $invoice->balance > 0) {
            AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));

            return;
        }

        if ($invoice->amount_paid > 0 || $invoice->credit_applied > 0) {
            $this->settlement->settle($invoice);

            return;
        }

        $invoice->status = InvoiceStatus::Cancelled;
        $invoice->cancelled_at = $this->clock->now()->utc();
        $invoice->save();
        $this->settlement->releaseGuards($invoice);
        AfterCommit::dispatch(new InvoiceCancelled($invoice->id, $invoice->client_id));
    }
}
