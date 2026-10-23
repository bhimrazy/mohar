<?php

use App\Modules\Billing\Enums\TaxNoteKind;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxNoteLine;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
| Numbered tax documents are immutable (ADR 0024 §1, vat-register AC 10): the invoice freeze that grows with the number,
| and the note models that refuse every change except the print count and the reserved CBMS columns.
*/

beforeEach(function () {
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.report_currency' => 'NPR']);
});

function taxNoteFor(Invoice $invoice): TaxNote
{
    return TaxNote::query()->create([
        'kind' => TaxNoteKind::Credit, 'number' => 'CN-1', 'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id, 'currency' => $invoice->currency,
        'issue_date' => '2026-10-20', 'issued_at' => '2026-10-20 06:00:00', 'invoice_number' => (string) $invoice->number, 'reason_code' => 'goodwill',
        'reason_text' => 'Goodwill', 'taxable_amount' => 1_000, 'tax1_amount' => 130, 'total' => 1_130, 'supply_class' => 'taxable',
        'bs_issue_date' => '2083-07-03', 'bs_period' => '2083-07', 'bs_fiscal_year' => '2083.084',
    ]);
}

dataset('frozen invoice fields', [
    'number' => ['number', 'X-9'],
    'numbered_at' => ['numbered_at', '2020-01-01 00:00:00'],
    'issue_date' => ['issue_date', '2020-01-01'],
    'client_snapshot' => ['client_snapshot', ['name' => 'Someone else']],
    'seller_snapshot' => ['seller_snapshot', ['legal_name' => 'Someone else']],
    'fiscal_taxable' => ['fiscal_taxable', 1],
    'fiscal_exempt' => ['fiscal_exempt', 1],
    'fiscal_tax1' => ['fiscal_tax1', 1],
    'fiscal_tax2' => ['fiscal_tax2', 1],
    'fiscal_total' => ['fiscal_total', 1],
    'report_currency' => ['report_currency', 'USD'],
    'report_rate' => ['report_rate', '2.000000000000'],
    'supply_class' => ['supply_class', 'export'],
    'bs_issue_date' => ['bs_issue_date', '2083-01-01'],
    'bs_period' => ['bs_period', '2083-01'],
    'bs_fiscal_year' => ['bs_fiscal_year', '2082.083'],
    'total' => ['total', 1],
]);

test('no model path changes a field frozen with the number', function (string $field, mixed $value) {
    $invoice = issueInvoice(billingClient(['currency' => 'NPR']), [10_000]);

    $before = DB::table('invoices')->where('id', $invoice->id)->value($field);

    expect(fn () => $invoice->fresh()->forceFill([$field => $value])->save())->toThrow(ImmutableInvoiceException::class)
        ->and(DB::table('invoices')->where('id', $invoice->id)->value($field))->toBe($before);
})->with('frozen invoice fields');

test('the cached credited and debited totals, the due date and the notes stay writable after numbering', function () {
    $invoice = issueInvoice(billingClient(['currency' => 'NPR']), [10_000])->fresh();
    $invoice->forceFill(['credited_total' => 5, 'debited_total' => 7, 'due_date' => '2026-12-01', 'notes' => 'Thanks'])->save();

    expect($invoice->fresh())->credited_total->toBe(5)->debited_total->toBe(7);
});

test('a proforma is allowed exactly one transition: the payment that assigns its number', function () {
    billingSettings(['sequential_paid_numbering' => true]);
    $invoice = issueInvoice(billingClient(['currency' => 'NPR']), [10_000]);

    // Before the number nothing of the fiscal set is frozen, so the settling call can write it.
    $invoice->fresh()->update(['issue_date' => '2026-10-01']);
    payInvoice($invoice, 10_000);

    expect($invoice->fresh())->number->toBe('1')->numbered_at->not->toBeNull()
        ->and(fn () => $invoice->fresh()->update(['issue_date' => '2026-10-02']))->toThrow(ImmutableInvoiceException::class);
});

test('a numbered invoice cannot be deleted, a draft can', function () {
    $draft = issueInvoice(billingClient(['currency' => 'NPR']), [10_000], status: 'draft');
    $numbered = issueInvoice(billingClient(['currency' => 'NPR']), [10_000]);

    expect(fn () => $numbered->fresh()->delete())->toThrow(ImmutableInvoiceException::class)
        ->and(Invoice::query()->whereKey($numbered->id)->exists())->toBeTrue();

    $draft->fresh()->delete();
    expect(Invoice::query()->whereKey($draft->id)->exists())->toBeFalse();
});

test('a numbered note changes only its print count and CBMS columns, and is never deleted', function () {
    $invoice = issueInvoice(billingClient(['currency' => 'NPR']), [10_000]);
    $note = taxNoteFor($invoice);

    $note->update(['print_count' => 2, 'cbms_status' => 'queued', 'cbms_reference' => 'R-1']);
    expect($note->fresh())->print_count->toBe(2)->cbms_status->toBe('queued');

    foreach (['number' => 'CN-2', 'total' => 1, 'reason_text' => 'Changed', 'bs_period' => '2083-01', 'report_rate' => '3.000000000000', 'kind' => TaxNoteKind::Debit] as $field => $value) {
        expect(fn () => $note->fresh()->update([$field => $value]))->toThrow(ImmutableInvoiceException::class);
    }

    expect(fn () => $note->fresh()->delete())->toThrow(ImmutableInvoiceException::class)
        ->and(TaxNote::query()->count())->toBe(1);
});

test('note lines are never updated or deleted', function () {
    $note = taxNoteFor(issueInvoice(billingClient(['currency' => 'NPR']), [10_000]));
    $line = TaxNoteLine::query()->create(['tax_note_id' => $note->id, 'invoice_item_id' => null, 'description' => 'Hosting', 'unit_amount' => 1_000, 'amount' => 1_000, 'tax1_amount' => 130]);

    expect(fn () => $line->update(['amount' => 1]))->toThrow(ImmutableInvoiceException::class)
        ->and(fn () => $line->delete())->toThrow(ImmutableInvoiceException::class);
});

test('the database refuses to delete an invoice, a client or a note that a note points at', function () {
    $invoice = issueInvoice(billingClient(['currency' => 'NPR']), [10_000]);
    $note = taxNoteFor($invoice);
    $line = TaxNoteLine::query()->create(['tax_note_id' => $note->id, 'description' => 'Late fee', 'unit_amount' => 1, 'amount' => 1]);

    foreach ([DB::table('invoices')->where('id', $invoice->id), DB::table('clients')->where('id', $invoice->client_id), DB::table('tax_notes')->where('id', $note->id)] as $query) {
        // A savepoint, so PostgreSQL's aborted-transaction state does not outlive the refused statement.
        expect(fn () => DB::transaction(fn () => $query->delete()))->toThrow(QueryException::class);
    }

    expect(TaxNote::query()->count())->toBe(1)->and($line->fresh())->not->toBeNull();
});

test('a refund row has at most one reservation, and its lines go with it', function () {
    $invoice = issueInvoice(billingClient(['currency' => 'NPR']), [10_000]);
    $refund = Transaction::factory()->create(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'type' => 'refund', 'status' => 'pending']);
    $reservation = fn () => DB::table('tax_note_reservations')->insertGetId(['invoice_id' => $invoice->id, 'transaction_id' => $refund->id, 'reason_code' => 'refunded', 'digest' => 'a', 'created_at' => now(), 'updated_at' => now()]);
    $id = $reservation();
    DB::table('tax_note_reservation_lines')->insert(['reservation_id' => $id, 'unit_amount' => 1_000, 'amount' => 1_000, 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::table('tax_note_reservations')->where('id', $id)->value('status'))->toBe('pending')
        ->and(fn () => DB::transaction($reservation))->toThrow(QueryException::class);

    DB::table('tax_note_reservations')->where('id', $id)->delete();
    expect(DB::table('tax_note_reservation_lines')->count())->toBe(0);
});
