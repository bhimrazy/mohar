<?php

namespace App\Modules\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the staff-review list of the fiscal backfill (ADR 0024 §2): an invoice, or one of its adjustments, whose
 * tax figures the migration had to estimate. Nepal VAT mode cannot be enabled while an entry is unseen.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $reason one of the REASON_* constants
 * @property string $subject "invoice", or "adjustment:<id>"
 * @property string|null $detail
 * @property CarbonImmutable|null $seen_at
 * @property int|null $seen_by
 * @property-read Invoice $invoice
 */
#[Fillable(['invoice_id', 'reason', 'subject', 'detail', 'seen_at', 'seen_by'])]
class InvoiceFiscalReview extends Model
{
    /** No single numbering payment could be identified; `numbered_at` is an estimate (the invoice's paid_at). */
    public const NUMBERING_INSTANT = 'numbering_instant_unknown';

    /** A value adjustment that stores no line and no tax split (cancellation, refund, manual). */
    public const LINE_LESS_ROW = 'line_less_row';

    /** An item cancellation that stored its net as `taxable_amount` on an untaxed line, or matches no line. */
    public const ITEM_CANCELLATION = 'item_cancellation_unclassified';

    public const REPORT_RATE = 'report_rate_unknown';

    public const LINES_MISMATCH = 'lines_do_not_match_totals';

    /** `report_rate` is today's rate, because the rate at numbering was not recorded. */
    public const REPORT_RATE_ESTIMATED = 'report_rate_estimated';

    /** Cancelling the invoice was refused: its credit note could not be issued (VAT PR 3b). The detail says why. */
    public const CANCEL_REFUSED = 'cancel_refused';

    /** A late fee was refused: its debit note could not be issued. */
    public const LATE_FEE_REFUSED = 'late_fee_refused';

    /** The cached credited/debited totals were wrong (written past by the previous release) and were recomputed. */
    public const CACHED_TOTALS = 'cached_totals_reconciled';

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUnseen(Builder $query): void
    {
        $query->whereNull('seen_at');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
