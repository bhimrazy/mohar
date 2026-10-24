<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Transaction;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The refund reservations of ADR 0024 §5: a refund of a payment on a numbered tax invoice reserves its credit note first
 * (the allocation, checked against both caps), and becomes a note only when the provider has paid. A pending reservation
 * counts in the line and invoice caps (`TaxNoteIssuer::pending`). Every method runs inside a transaction that holds the
 * locks of §4 (payment, invoice, client), and the reservation row is locked after them.
 */
class RefundReservations
{
    public const REASON_CODE = 'refunded';

    public function __construct(private TaxNoteIssuer $issuer) {}

    /**
     * Reserve the credit note for a refund row: plan the allocation of `$gross` over the invoice's lines (refused when a cap
     * would be exceeded) and store it with the note's reason. No number is taken.
     *
     * @throws BillingException
     */
    public function reserve(Invoice $invoice, Transaction $refund, int $gross, string $reasonText, ?int $userId): void
    {
        $plan = $this->issuer->plan(NoteRequest::creditAmount($invoice->id, $gross, self::REASON_CODE, $reasonText, false, $userId, 'refund'), $invoice);
        $now = now();
        $id = DB::table('tax_note_reservations')->insertGetId([
            'invoice_id' => $invoice->id, 'transaction_id' => $refund->id, 'reason_code' => self::REASON_CODE, 'reason_text' => mb_substr($reasonText, 0, 500),
            'digest' => $plan->digest, 'created_by' => $userId, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('tax_note_reservation_lines')->insert(array_map(fn (array $part, int $i): array => [
            'reservation_id' => $id, 'invoice_item_id' => $part['item_id'], 'quantity' => 1, 'unit_amount' => $part['net'], 'amount' => $part['net'],
            'taxable' => $part['taxable'], 'tax1_amount' => $part['tax1'], 'tax2_amount' => $part['tax2'], 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
        ], $plan->parts, array_keys($plan->parts)));
    }

    /** The refund's reservation while it is still pending, locked; null when it has none or something settled it first. */
    public function pending(Transaction $refund): ?stdClass
    {
        return DB::table('tax_note_reservations')->where('transaction_id', $refund->id)->where('status', 'pending')->lockForUpdate()->first();
    }

    /**
     * @return list<stdClass>
     */
    public function lines(stdClass $reservation): array
    {
        return DB::table('tax_note_reservation_lines')->where('reservation_id', $reservation->id)->orderBy('sort_order')->orderBy('id')->get()->values()->all();
    }

    public function finalise(stdClass $reservation, ?int $noteId): void
    {
        DB::table('tax_note_reservations')->where('id', $reservation->id)->where('status', 'pending')->update(['status' => 'finalised', 'tax_note_id' => $noteId, 'updated_at' => now()]);
    }

    /** The provider did not pay: the reservation is released and its allocation counts in no cap. */
    public function release(Transaction $refund): void
    {
        DB::table('tax_note_reservations')->where('transaction_id', $refund->id)->where('status', 'pending')->update(['status' => 'released', 'updated_at' => now()]);
    }
}
