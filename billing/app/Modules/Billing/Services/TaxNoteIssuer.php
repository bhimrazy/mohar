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
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Exceptions\StaleNoteException;
use App\Modules\Billing\Jobs\RenderTaxNotePdf;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxNoteLine;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Modules\Billing\Services\Tax\TaxRates;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BikramSambat;
use App\Modules\Billing\Support\BikramSambatDate;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\NoteAllocator;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\CalendarDate;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use OutOfRangeException;
use stdClass;

/**
 * Issues credit and debit notes against a numbered invoice (ADR 0024, vat-register §5.1). Confirm-then-apply (ADR 0021):
 * preview() plans a note, issue() takes the invoice lock, plans it again and refuses when the invoice version or the
 * plan's digest differs from the preview's.
 *
 * Lock order (ADR 0024 §4): payments, then the invoice, then the client, then the invoice's pending reservations, then the
 * sequence row, taken last. A credit note reduces the balance first; the part above it goes to account credit when staff
 * choose that settlement, and is refused otherwise (refund settlement comes with PR 4). A reversal repeats one credit
 * note's lines as a debit note and takes back the account credit that note posted. The activity entry
 * `billing.tax_note.issued` is written in the same transaction.
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
        private CreditLedgerService $credit,
        private ActivityLogger $activity,
        private MoneyFormatter $money,
        private BillingSettings $settings,
        private NepalVatMode $mode,
    ) {}

    /** What can still be credited on the invoice: its value, plus debits, less credits and pending refund reservations. */
    public function creditable(Invoice $invoice): int
    {
        return max(0, (int) $invoice->fiscal_total + $invoice->debited_total - $invoice->credited_total - $this->pending($invoice)['gross']);
    }

    /**
     * Each line with the gross still creditable on it, for the by-line form; empty when the invoice takes no credit by line.
     *
     * @return list<array{id: int, description: string, left: int}>
     */
    public function creditableLines(Invoice $invoice): array
    {
        try {
            return array_map(fn (array $line): array => ['id' => $line['id'], 'description' => $line['description'], 'left' => max(0, $line['gross'] - $line['used_gross'])], $this->lines($invoice));
        } catch (BillingException) {
            return [];
        }
    }

    /**
     * Why no credit note can be issued on the invoice now, or null. Shown on the disabled action.
     */
    public function creditBlocked(Invoice $invoice): ?string
    {
        if (($blocked = $this->unclassifiedBlock($invoice)) !== null) {
            return $blocked;
        }

        if ($invoice->status === InvoiceStatus::Cancelled || $invoice->status === InvoiceStatus::Refunded) {
            return __('The invoice is :status; nothing is left to credit.', ['status' => mb_strtolower($invoice->status->label())]);
        }

        if ($this->creditable($invoice) === 0) {
            return __('Nothing is left to credit on this invoice.');
        }

        try {
            $this->lines($invoice);
        } catch (BillingException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Why no debit note (or reversal) can be issued on the invoice now, or null. A cancelled or refunded invoice is closed:
     * a new invoice is raised instead.
     */
    public function debitBlocked(Invoice $invoice): ?string
    {
        if (($blocked = $this->unclassifiedBlock($invoice)) !== null) {
            return $blocked;
        }

        if ($invoice->status->isPayable() || $invoice->status === InvoiceStatus::Paid) {
            return null;
        }

        return __('The invoice is :status, so it takes no debit note or reversal; raise a new invoice instead.', ['status' => mb_strtolower($invoice->status->label())]);
    }

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
            [$invoice, $plan] = $this->lockAndPlan($request);

            if ($invoice->version !== $version || $plan->digest !== $digest) {
                throw StaleNoteException::make();
            }

            return $this->write($request, $invoice, $plan, $this->clock->now()->utc(), true);
        });
    }

    /**
     * Issue a note in one step, for the paths that cancel, cancel lines or add a late fee (vat-register §5.4): nothing
     * was previewed, so the plan is built under the lock and written at once. Call it inside the transaction that holds the
     * invoice lock; `$at` is that transaction's one instant and dates the note and its adjustment. With `$settle` false the
     * invoice is left as it is after the note (the caller is cancelling it).
     *
     * @throws BillingException when a rule or a cap is violated
     */
    public function issueNow(NoteRequest $request, CarbonImmutable $at, bool $settle = true): TaxNote
    {
        return DB::transaction(function () use ($request, $at, $settle): TaxNote {
            [$invoice, $plan] = $this->lockAndPlan($request);

            return $this->write($request, $invoice, $plan, $at, $settle);
        });
    }

    /**
     * Issue the note a refund reserved: exactly the stored allocation, at the finalising transaction's instant `$at`. The
     * caller holds the payment, invoice, client and reservation locks (ADR 0024 §4) and has checked that the mode is in
     * effect at `$at`; it then takes the refund off `amount_paid`, so the note's credit and the refund net on the balance.
     *
     * @param  list<stdClass>  $lines  the reservation's lines
     */
    public function issueReserved(Invoice $invoice, array $lines, string $reasonCode, string $reasonText, ?int $userId, CarbonImmutable $at): TaxNote
    {
        $items = DB::table('invoice_items')->where('invoice_id', $invoice->id)->pluck('description', 'id');
        $parts = array_map(fn (stdClass $line): array => [
            'item_id' => $line->invoice_item_id !== null ? (int) $line->invoice_item_id : null,
            'description' => (string) ($items[$line->invoice_item_id] ?? $reasonText),
            'taxable' => (bool) $line->taxable, 'gross' => (int) $line->amount + (int) $line->tax1_amount + (int) $line->tax2_amount,
            'net' => (int) $line->amount, 'tax1' => (int) $line->tax1_amount, 'tax2' => (int) $line->tax2_amount,
        ], $lines);
        $net = fn (bool $taxable): int => array_sum(array_map(fn (array $part): int => $part['net'], array_filter($parts, fn (array $part): bool => $part['taxable'] === $taxable)));
        $plan = new NotePreview('credit', $parts, array_sum(array_column($parts, 'gross')), $net(true), $net(false), array_sum(array_column($parts, 'tax1')), array_sum(array_column($parts, 'tax2')),
            $invoice->balance, $invoice->version, '', 'refund');

        return $this->write(NoteRequest::creditAmount($invoice->id, $plan->total, $reasonCode, $reasonText, $userId === null, $userId, 'refund'), $invoice, $plan, $at, false);
    }

    /**
     * Move an amount above the balance to account credit with the note's id (the refund finalised after a payment arrived
     * meanwhile). Called with the invoice and client locked.
     */
    public function creditExcess(Invoice $invoice, TaxNote $note, int $amount, ?int $userId): void
    {
        $this->postToCredit($invoice, $note, $amount, $userId);
    }

    /**
     * @return array{0: Invoice, 1: NotePreview}
     */
    private function lockAndPlan(NoteRequest $request): array
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($request->invoiceId);
        // The client next: the note's own insert takes a key-share lock on it, and the refund paths that hold the client
        // for update then wait for the sequence. Locking it here keeps both in the same order.
        Client::query()->whereKey($invoice->client_id)->lockForUpdate()->firstOrFail();
        DB::table('tax_note_reservations')->where('invoice_id', $invoice->id)->where('status', 'pending')->orderBy('id')->lockForUpdate()->pluck('id');

        return [$invoice, $this->plan($request, $invoice)];
    }

    private function write(NoteRequest $request, Invoice $invoice, NotePreview $plan, CarbonImmutable $at, bool $settle): TaxNote
    {
        // Before its start day the old paths run and staff cannot issue notes (ADR 0024 §8).
        // The mode is read under the shared lock, like the old paths: switching it off waits for this transaction.
        if ($this->mode->enabledLocked() && ! $this->mode->inEffectLocked($at)) {
            throw new BillingException(__('Nepal VAT mode starts on :date; notes can be issued from then.', ['date' => $this->mode->enabledFrom()]));
        }

        $date = $this->clock->localDate($at);
        $bs = $this->bsDate($date);
        $credit = $request->kind === 'credit';
        $adjustment = $this->settlement->adjust(
            $invoice, $credit ? AdjustmentKind::Credit : AdjustmentKind::Debit, $credit ? AdjustmentReason::CreditNote : AdjustmentReason::DebitNote,
            $plan->total, userId: $request->userId, taxableAmount: $plan->taxable, taxAmount: $plan->tax1 + $plan->tax2, at: $at,
        );
        // The sequence row is locked last, just before the note is inserted.
        $number = $this->numbers->next($credit ? DocumentNumbers::CREDIT_NOTE : DocumentNumbers::DEBIT_NOTE, CalendarDate::parse($date));

        $note = (new TaxNote)->forceFill([
            'kind' => $request->kind, 'number' => $number, 'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id, 'currency' => $invoice->currency,
            'issue_date' => $date, 'issued_at' => $at, 'invoice_number' => (string) $invoice->number, 'invoice_bs_issue_date' => $invoice->bs_issue_date,
            'reason_code' => $request->reasonCode, 'reason_text' => $request->reasonText, 'taxable_amount' => $plan->taxable, 'exempt_amount' => $plan->exempt,
            'tax1_amount' => $plan->tax1, 'tax2_amount' => $plan->tax2, 'total' => $plan->total, 'report_currency' => $invoice->report_currency,
            'report_rate' => $invoice->report_rate, 'supply_class' => $invoice->supply_class, 'tax1_is_vat' => $invoice->fiscal_tax1_is_vat, 'tax2_is_vat' => $invoice->fiscal_tax2_is_vat, 'seller_snapshot' => $invoice->seller_snapshot,
            'buyer_snapshot' => $invoice->client_snapshot, 'bs_issue_date' => $bs->toIso(), 'bs_period' => BikramSambat::period($bs),
            'bs_fiscal_year' => BikramSambat::fiscalYear($bs), 'automatic' => $request->automatic, 'created_by' => $request->userId,
            'reverses_tax_note_id' => $request->reverses, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $note->save();

        foreach ($plan->parts as $i => $part) {
            TaxNoteLine::query()->create([
                'tax_note_id' => $note->id, 'invoice_item_id' => $part['item_id'], 'description' => mb_substr($part['description'], 0, 255), 'quantity' => 1,
                'unit_amount' => $part['net'], 'amount' => $part['net'], 'taxable' => $part['taxable'], 'tax1_amount' => $part['tax1'], 'tax2_amount' => $part['tax2'], 'sort_order' => $i,
            ]);
        }

        // A note aimed at one line names it on its adjustment.
        $reverses = $request->reverses !== null ? (string) TaxNote::query()->whereKey($request->reverses)->value('number') : null;
        // The balance card names the note; its reason text is on the note itself.
        $label = match (true) {
            $reverses !== null => __('Debit note :number (reverses :reverses)', ['number' => $number, 'reverses' => $reverses]),
            $credit => __('Credit note :number', ['number' => $number]),
            default => __('Debit note :number', ['number' => $number]),
        };
        $adjustment->forceFill(['tax_note_id' => $note->id, 'invoice_item_id' => count($plan->parts) === 1 ? $plan->parts[0]['item_id'] : null, 'description' => $label])->save();
        $this->settleToCredit($invoice, $note, $plan, $request->userId);
        if ($settle) {
            $this->afterNote($invoice, $credit);
        }
        $this->activity->log($reverses !== null
            ? __('Debit note :number issued, reversing credit note :reverses', ['number' => $number, 'reverses' => $reverses])
            : __(':kind note :number issued', ['kind' => $credit ? __('Credit') : __('Debit'), 'number' => $number]), $note, $invoice->client_id, [
                'tax_note_id' => $note->id, 'number' => $number, 'kind' => $request->kind, 'invoice_id' => $invoice->id, 'invoice_number' => $invoice->number,
                'amount' => $plan->total, 'currency' => $invoice->currency, 'settlement' => $plan->settlement, 'to_credit' => $plan->toCredit,
                'reason_code' => $request->reasonCode, 'reverses_tax_note_id' => $request->reverses, 'reverses_number' => $reverses, 'due_date' => $plan->dueDate,
                'source' => $request->automatic ? ($request->source ?? 'automation') : 'staff',
            ], 'billing.tax_note.issued');
        if ($request->notify && $this->settings->bool('pdf.attach_to_emails')) {
            // In the client's language, which their note email is composed in.
            RenderTaxNotePdf::dispatch($note->id, Client::query()->whereKey($invoice->client_id)->value('language') ?: null)->afterCommit();
        }
        event($credit
            ? new CreditNoteIssued($note->id, $number, $invoice->id, $invoice->client_id, $plan->total, $invoice->currency, $request->automatic, $request->notify)
            : new DebitNoteIssued($note->id, $number, $invoice->id, $invoice->client_id, $plan->total, $invoice->currency, $request->automatic, $request->notify));

        return $note;
    }

    public function plan(NoteRequest $request, Invoice $invoice, bool $reserving = false): NotePreview
    {
        $this->guard($request, $invoice, $reserving);
        $credit = $request->kind === 'credit';
        $parts = match (true) {
            $credit => $this->creditParts($request, $invoice),
            $request->reverses !== null => $this->reversalParts((int) $request->reverses, $invoice),
            default => $this->debitParts($request, $invoice),
        };
        $total = array_sum(array_column($parts, 'gross'));
        $money = fn (int $amount): string => $this->money->amount($amount, $invoice->currency);
        $toCredit = match (true) {
            $credit && $request->settlement === 'credit' => max(0, $total - $invoice->balance),
            $request->reverses !== null => $this->creditPostedBy((int) $request->reverses),
            default => 0,
        };
        $balanceAfter = $invoice->balance + ($credit ? $toCredit - $total : $total - $toCredit);

        if ($total <= 0) {
            throw new BillingException(__('A note needs an amount above zero.'));
        }

        if ($credit) {
            $available = $this->creditable($invoice);

            if ($total > $available) {
                throw new BillingException(__('At most :amount can still be credited on this invoice.', ['amount' => $money(max(0, $available))]));
            }

            if ($balanceAfter < 0 && $request->settlement !== 'refund') {
                throw new BillingException(__('A credit note above the open balance of :amount needs a settlement: choose account credit.', ['amount' => $money($invoice->balance)]));
            }
        }

        if ($toCredit > 0 && Client::query()->whereKey($invoice->client_id)->value('currency') !== $invoice->currency) {
            throw new BillingException(__('Account credit is kept in the client currency, and this invoice is in :currency.', ['currency' => $invoice->currency]));
        }

        $dueDate = ! $credit && $invoice->status === InvoiceStatus::Paid && $balanceAfter > 0 ? $this->reopenDueDate() : null;
        $creditAfter = $request->reverses !== null ? $this->credit->balance($invoice->client_id, $invoice->currency)->amount - $toCredit : null;
        $net = fn (bool $taxable): int => array_sum(array_map(fn (array $part): int => $part['net'], array_filter($parts, fn (array $part): bool => $part['taxable'] === $taxable)));
        $tax1 = array_sum(array_column($parts, 'tax1'));
        $tax2 = array_sum(array_column($parts, 'tax2'));

        return new NotePreview(
            $request->kind, $parts, $total, $net(true), $net(false), $tax1, $tax2, $balanceAfter, $invoice->version,
            hash('sha256', (string) json_encode([$request->kind, $invoice->id, $request->reasonCode, $total, $parts, $toCredit, $request->reverses, $dueDate, $creditAfter])),
            $toCredit > 0 ? 'credit' : ($request->settlement === 'refund' ? 'refund' : 'balance'), $toCredit, $dueDate,
            $creditAfter,
        );
    }

    /**
     * Why notes cannot be issued at all now: the mode is off, or it has not reached its start day. A refund reserves its note
     * from the mode being switched on, so `$beforeStart` lets the start day pass (ADR 0024 §5).
     */
    public function modeBlocked(bool $beforeStart = false): ?string
    {
        if (! $this->mode->enabled()) {
            return (string) __('Notes can be issued only while Nepal VAT mode is on.');
        }

        return $beforeStart || $this->mode->inEffect($this->clock->now()) ? null : (string) __('Nepal VAT mode starts on :date; notes can be issued from then.', ['date' => $this->mode->enabledFrom()]);
    }

    /** A taxed invoice whose tax was never split into Nepal VAT and other tax takes no note, now or later. */
    private function unclassifiedBlock(Invoice $invoice): ?string
    {
        if ($invoice->fiscal_tax1_is_vat !== null || (int) $invoice->fiscal_tax1 + (int) $invoice->fiscal_tax2 === 0) {
            return null;
        }

        return __('This invoice\'s tax was never split into Nepal VAT and other tax, so it takes no note. Ask the person who runs the system to run "php artisan billing:fiscal-vat-split" first.');
    }

    private function guard(NoteRequest $request, Invoice $invoice, bool $reserving = false): void
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

        // Notes exist only while the mode is in effect, and a taxed invoice without flags would write a note the register cannot
        // place (a note's flags are copied once and never change).
        if (($blocked = $this->modeBlocked($reserving) ?? $this->unclassifiedBlock($invoice)) !== null) {
            throw new BillingException($blocked);
        }

        if ($this->clock->isFallback()) {
            throw new BillingException(__('The company time zone is not valid; fix it before issuing a tax document.'));
        }

        if (($request->reasonCode === 'reverses_credit_note') !== ($request->reverses !== null)) {
            throw new BillingException(__('Reverse a credit note from the note itself.'));
        }

        if ($request->kind === 'debit' && ($blocked = $this->debitBlocked($invoice)) !== null) {
            throw new BillingException($blocked);
        }

        if ($request->kind === 'debit' && $request->reverses === null) {
            // ADR 0024 §3: debit notes together add at most the invoice's original value, which also bounds the tax
            // arithmetic. Late fees after numbering count too (they are in debited_total); a reversal is bounded by its
            // credit note instead, so it does not count.
            $left = $invoice->fiscal_total - ($invoice->debited_total - $this->reversedTotal($invoice));

            if ((int) $request->amount > $left) {
                throw new BillingException(__('Debit notes and late fees together add at most the value of the invoice; :amount is left.', ['amount' => $this->money->amount(max(0, $left), $invoice->currency)]));
            }
        }

        $this->bsDate($this->clock->localDate($this->clock->now()));
    }

    private function bsDate(string $date): BikramSambatDate
    {
        try {
            return BikramSambat::fromAd(CalendarDate::parse($date));
        } catch (OutOfRangeException) {
            throw new BillingException(__('The date is outside the Bikram Sambat table; no tax document can be numbered.'));
        }
    }

    /**
     * @return list<Part>
     */
    private function creditParts(NoteRequest $request, Invoice $invoice): array
    {
        if ($request->cancel) {
            return $this->cancelParts($invoice, (int) $request->amount);
        }

        $lines = $this->lines($invoice);

        return $request->lines !== null
            ? $this->allocator->byLines($lines, $request->lines, $invoice->currency)
            : $this->allocator->byAmount($lines, (int) $request->amount, $invoice->currency);
    }

    /**
     * The parts of the note that cancels an invoice: what each line still holds (a discount line as a negative part, so it
     * goes with the lines it discounts), plus every late fee as a part of its own, with its own net and tax. When the open
     * balance is less than all of that (a payment or applied credit stays), every part is scaled down in proportion and the
     * rounding difference goes to the largest part, so the note is exactly the balance.
     *
     * @return list<Part>
     */
    private function cancelParts(Invoice $invoice, int $gross): array
    {
        $parts = [];

        foreach ($this->lines($invoice, discounts: true) as $line) {
            $left = $line['gross'] - $line['used_gross'];

            if ($left !== 0) {
                $parts[] = ['item_id' => $line['id'], 'description' => $line['description'], 'taxable' => $line['taxable'], 'gross' => $left,
                    'net' => $line['net'] - $line['used_net'], 'tax1' => $line['tax1'] - $line['used_tax1'], 'tax2' => $line['tax2'] - $line['used_tax2']];
            }
        }

        $parts = [...$parts, ...$this->lateFeeParts($invoice)];
        $total = array_sum(array_column($parts, 'gross'));

        if ($total <= 0 || $gross <= 0 || $gross > $total) {
            throw new BillingException(__('At most :amount can still be credited on this invoice.', ['amount' => $this->money->amount(max(0, $total), $invoice->currency)]));
        }

        return $gross === $total ? $parts : $this->scale($parts, $gross, $total);
    }

    /**
     * The late fees of the invoice, each as a part: debit notes without a line of the invoice, and old-style `late_fee` rows
     * (whether written before the invoice was numbered, and so in its snapshot, or after).
     *
     * @return list<Part>
     */
    private function lateFeeParts(Invoice $invoice): array
    {
        $parts = [];
        $tax = $invoice->tax1_amount + $invoice->tax2_amount;

        foreach (DB::table('invoice_adjustments')->where('invoice_id', $invoice->id)->where('reason', AdjustmentReason::LateFee->value)->where('kind', AdjustmentKind::Debit->value)->orderBy('id')->get() as $row) {
            $tax1 = $tax === 0 ? 0 : TaxMath::roundDiv((int) $row->tax_amount * $invoice->tax1_amount, $tax);
            $parts[] = ['item_id' => null, 'description' => (string) ($row->description ?? __('Late payment fee')), 'taxable' => (int) $row->taxable_amount > 0, 'gross' => (int) $row->amount,
                'net' => (int) $row->amount - (int) $row->tax_amount, 'tax1' => $tax1, 'tax2' => (int) $row->tax_amount - $tax1];
        }

        $lines = TaxNoteLine::query()->join('tax_notes as n', 'n.id', '=', 'tax_note_lines.tax_note_id')
            ->where('n.invoice_id', $invoice->id)->where('n.kind', 'debit')->whereNull('n.reverses_tax_note_id')->whereNull('tax_note_lines.invoice_item_id')
            ->orderBy('tax_note_lines.id')->toBase()->get(['tax_note_lines.description', 'tax_note_lines.taxable', 'tax_note_lines.amount', 'tax_note_lines.tax1_amount', 'tax_note_lines.tax2_amount']);

        foreach ($lines as $line) {
            $parts[] = ['item_id' => null, 'description' => (string) $line->description, 'taxable' => (bool) $line->taxable, 'gross' => (int) $line->amount + (int) $line->tax1_amount + (int) $line->tax2_amount,
                'net' => (int) $line->amount, 'tax1' => (int) $line->tax1_amount, 'tax2' => (int) $line->tax2_amount];
        }

        return $parts;
    }

    /**
     * Scale the signed parts so they add up to `$gross`: each part's gross in proportion, then its net and taxes in the proportion
     * of its own value, half away from zero; the rounding difference goes to the part of largest value.
     *
     * @param  list<Part>  $parts
     * @return list<Part>
     */
    private function scale(array $parts, int $gross, int $total): array
    {
        $round = function (int $numerator, int $denominator): int {
            if ($denominator === 0) {
                return 0;
            }

            return (($numerator < 0) !== ($denominator < 0) ? -1 : 1) * intdiv(2 * abs($numerator) + abs($denominator), 2 * abs($denominator));
        };
        $shares = array_map(fn (array $part): int => $round($part['gross'] * $gross, $total), $parts);
        $weights = array_map(fn (array $part): int => abs($part['gross']), $parts);
        $largest = (int) array_search(max([0, ...$weights]), $weights, true);

        $shares[$largest] += $gross - array_sum($shares);
        $scaled = [];

        foreach ($parts as $i => $part) {
            if ($shares[$i] === 0) {
                continue;
            }

            $tax1 = $round($part['tax1'] * $shares[$i], $part['gross']);
            $tax2 = $round($part['tax2'] * $shares[$i], $part['gross']);
            $scaled[] = [...$part, 'gross' => $shares[$i], 'net' => $shares[$i] - $tax1 - $tax2, 'tax1' => $tax1, 'tax2' => $tax2];
        }

        return $scaled;
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
     * The credit note's own lines, repeated (vat-register §5.5). It must be a credit note of this invoice that no note has
     * reversed yet; the unique `reverses_tax_note_id` holds that against a concurrent reversal.
     *
     * @return list<Part>
     */
    private function reversalParts(int $creditNoteId, Invoice $invoice): array
    {
        $note = TaxNote::query()->with('lines')->where('invoice_id', $invoice->id)->whereKey($creditNoteId)->first();

        if ($note === null) {
            throw new BillingException(__('That note is not on this invoice.'));
        }

        if ($note->kind !== 'credit') {
            throw new BillingException($note->reverses_tax_note_id !== null
                ? __('Debit note :number reverses a credit note and cannot itself be reversed.', ['number' => $note->number])
                : __('Only a credit note can be reversed; correct debit note :number with a credit note.', ['number' => $note->number]));
        }

        if (TaxNote::query()->where('reverses_tax_note_id', $note->id)->exists()) {
            throw new BillingException(__('Credit note :number is already reversed.', ['number' => $note->number]));
        }

        return array_values($note->lines->map(fn (TaxNoteLine $line): array => [
            'item_id' => $line->invoice_item_id, 'description' => $line->description, 'taxable' => $line->taxable,
            'gross' => $line->amount + $line->tax1_amount + $line->tax2_amount, 'net' => $line->amount, 'tax1' => $line->tax1_amount, 'tax2' => $line->tax2_amount,
        ])->all());
    }

    /** The account credit a credit note posted, which its reversal takes back. */
    private function creditPostedBy(int $creditNoteId): int
    {
        return (int) DB::table('invoice_adjustments')->where('tax_note_id', $creditNoteId)
            ->where('reason', AdjustmentReason::CreditNoteToCredit->value)->where('kind', AdjustmentKind::Debit->value)->sum('amount');
    }

    private function reversedTotal(Invoice $invoice): int
    {
        return (int) TaxNote::query()->where('invoice_id', $invoice->id)->whereNotNull('reverses_tax_note_id')->sum('total');
    }

    /**
     * The settlement rows of §4.4, each with the note's id: a credit note's part above the balance goes to account
     * credit; a reversal takes back what its credit note posted, even into a negative balance, as other reversals do.
     */
    private function settleToCredit(Invoice $invoice, TaxNote $note, NotePreview $plan, ?int $userId): void
    {
        if ($plan->toCredit === 0) {
            return;
        }

        $this->postToCredit($invoice, $note, $plan->toCredit, $userId);
    }

    private function postToCredit(Invoice $invoice, TaxNote $note, int $toCredit, ?int $userId): void
    {
        $reversal = $note->reverses_tax_note_id !== null;
        $text = $reversal ? __('Credit taken back by debit note :number', ['number' => $note->number]) : __('Credit note :number to account credit', ['number' => $note->number]);
        $this->settlement->adjust($invoice, $reversal ? AdjustmentKind::Credit : AdjustmentKind::Debit, AdjustmentReason::CreditNoteToCredit, $toCredit, $text, userId: $userId, taxNoteId: $note->id);
        $this->credit->post($invoice->client_id, Money::of($reversal ? -$toCredit : $toCredit, $invoice->currency), $reversal ? CreditReason::CreditNoteReversed : CreditReason::CreditNote,
            $invoice->id, description: $text, staffId: $userId, allowNegative: $reversal, taxNoteId: $note->id);
    }

    /**
     * The creditable lines with what earlier notes, pending reservations and classified old item cancellations used.
     *
     * @return list<Line>
     */
    private function lines(Invoice $invoice, bool $discounts = false): array
    {
        $items = DB::table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')->get();

        if (! $discounts && $items->contains(fn (stdClass $item): bool => (int) $item->amount < 0)) {
            throw new BillingException(__('This invoice has a discount line, so no credit note can be issued on it. To correct it, cancel the invoice and raise a new one.'));
        }

        $items = $items->values();
        $weights = array_values($items->map(fn (stdClass $item): int => (int) $item->tax_amount)->all());
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

        return array_values(array_filter($lines, fn (array $line): bool => $discounts ? $line['gross'] !== 0 : $line['gross'] > 0));
    }

    /**
     * @return array<int, array{item: int, net: int, tax1: int, tax2: int}>
     */
    private function usedByNotes(Invoice $invoice): array
    {
        return TaxNoteLine::query()->join('tax_notes as n', 'n.id', '=', 'tax_note_lines.tax_note_id')
            ->where('n.invoice_id', $invoice->id)->whereNotNull('tax_note_lines.invoice_item_id')->groupBy('tax_note_lines.invoice_item_id')
            ->selectRaw("tax_note_lines.invoice_item_id as item, sum(case when n.kind = 'credit' then tax_note_lines.amount else -tax_note_lines.amount end) as net, "
                ."sum(case when n.kind = 'credit' then tax_note_lines.tax1_amount else -tax_note_lines.tax1_amount end) as tax1, "
                ."sum(case when n.kind = 'credit' then tax_note_lines.tax2_amount else -tax_note_lines.tax2_amount end) as tax2")->toBase()->get()
            ->map(fn (stdClass $row): array => ['item' => (int) $row->item, 'net' => (int) $row->net, 'tax1' => (int) $row->tax1, 'tax2' => (int) $row->tax2])->values()->all();
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
            'gross' => (int) $rows->sum(fn (stdClass $row): int => (int) $row->amount + (int) $row->tax1_amount + (int) $row->tax2_amount),
            'lines' => $rows->filter(fn (stdClass $row): bool => $row->invoice_item_id !== null)
                ->map(fn (stdClass $row): array => ['item' => (int) $row->invoice_item_id, 'net' => (int) $row->amount, 'tax1' => (int) $row->tax1_amount, 'tax2' => (int) $row->tax2_amount])->values()->all(),
        ];
    }

    /**
     * Old-style `item_cancellation` rows count against the line they cancelled, found by the exact text the old path wrote
     * ("Cancelled line: <description>", in this language or in English). Rows that match no line count against the
     * invoice cap only.
     *
     * @param  array<int, stdClass>  $items
     * @param  array<int, Line>  $lines
     * @return array<int, array{item: int, net: int, tax1: int, tax2: int}>
     */
    private function usedByOldRows(Invoice $invoice, array $items, array $lines): array
    {
        $rows = DB::table('invoice_adjustments')->where('invoice_id', $invoice->id)->where('reason', 'item_cancellation')->where('kind', 'credit')->orderBy('id')->get();
        $taken = [];
        $used = [];

        foreach ($rows as $row) {
            $item = collect($items)->first(function (stdClass $item) use ($row, $taken, $lines): bool {
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
     * A paid invoice that owes again after a note is a correction made today, not a late payment: it is unpaid with a
     * new due date from today on the normal payment terms, and the reminder, overdue notice and auto-capture state start
     * again. `late_fee_applied_at` stays: one late fee per invoice, so a fee charged before it was paid is not repeated.
     */
    private function reopen(Invoice $invoice): void
    {
        $invoice->forceFill([
            'status' => InvoiceStatus::Unpaid, 'paid_at' => null, 'due_date' => $this->reopenDueDate(), 'overdue_notified_at' => null,
            'reminder_log' => null, 'capture_attempts' => 0, 'first_capture_failure_on' => null, 'last_capture_attempt_at' => null,
        ])->save();
    }

    private function reopenDueDate(): string
    {
        return $this->clock->today()->addDays(max(0, $this->settings->int('invoice_days_before_due', 14)))->toDateString();
    }

    /**
     * A credit note that brings the balance to zero settles the invoice (paid), or cancels it when nothing was paid. A
     * debit note that leaves a paid invoice owing again (a reversal of a note that settled it) makes it unpaid.
     */
    private function afterNote(Invoice $invoice, bool $credit): void
    {
        if (! $credit && $invoice->status === InvoiceStatus::Paid && $invoice->balance > 0) {
            $this->reopen($invoice);
        }

        if (! $credit || $invoice->balance > 0) {
            AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));

            return;
        }

        if ($invoice->amount_paid > 0 || $invoice->credit_applied > 0) {
            if (! $this->settlement->settle($invoice)) {
                AfterCommit::dispatch(new InvoiceModified($invoice->id, $invoice->client_id));
            }

            return;
        }

        $invoice->status = InvoiceStatus::Cancelled;
        $invoice->cancelled_at = $this->clock->now()->utc();
        $invoice->save();
        $this->settlement->releaseGuards($invoice);
        AfterCommit::dispatch(new InvoiceCancelled($invoice->id, $invoice->client_id));
    }
}
