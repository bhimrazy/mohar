<?php

use App\Events\Billing\CreditNoteIssued;
use App\Events\Billing\DebitNoteIssued;
use App\Models\User;
use App\Modules\Billing\Actions\CancelInvoiceItems;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Exceptions\StaleNoteException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

/*
| The note issuer (ADR 0024, vat-register §5.1; AC 1 to 6, 11 and 48): Nepal VAT 13%, NPR, Nepal time. These invoices are
| numbered at publish and unpaid, so a note settles against the balance.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function noteClient(): Client
{
    return Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
}

/** Issue a note the way the confirm does: preview, then issue bound to it. */
function issueNote(NoteRequest $request): TaxNote
{
    $issuer = app(TaxNoteIssuer::class);
    $preview = $issuer->preview($request);

    return $issuer->issue($request, $preview->version, $preview->digest);
}

/** @return array<int, int> item id => gross of each line */
function lineGross(Invoice $invoice): array
{
    return $invoice->items->mapWithKeys(fn ($item) => [$item->id => $item->amount + $item->tax_amount])->all();
}

test('a full credit note is numbered CN-1, cites the invoice and its BS date, and cancels an unpaid invoice (AC 1)', function () {
    Event::fake([CreditNoteIssued::class]);
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);

    $note = issueNote(NoteRequest::creditLines($invoice->id, lineGross($invoice), 'invoice_cancelled', 'Cancelled at the client\'s request'));

    expect($note)->number->toBe('CN-1')->invoice_number->toBe($invoice->number)->invoice_bs_issue_date->toBe('2083-07-03')
        ->taxable_amount->toBe(10_000)->tax1_amount->toBe(1_300)->exempt_amount->toBe(0)->total->toBe(11_300)
        ->bs_issue_date->toBe('2083-07-03')->bs_period->toBe('2083-07')->issued_at->toEqual(CarbonImmutable::now())
        ->and($note->lines)->toHaveCount(1)
        ->and($invoice->fresh())->status->toBe(InvoiceStatus::Cancelled)->balance->toBe(0)->credited_total->toBe(11_300)
        ->and(DB::table('invoice_adjustments')->where('tax_note_id', $note->id)->count())->toBe(1);
    Event::assertDispatched(CreditNoteIssued::class, fn (CreditNoteIssued $event) => $event->number === 'CN-1' && $event->amount === 11_300 && ! $event->automatic);
});

test('notes on different invoices take consecutive numbers and a rolled-back note consumes none (AC 2)', function () {
    $client = noteClient();
    [$a, $b] = [issueInvoice($client, [10_000])->fresh(['items']), issueInvoice($client, [20_000])->fresh(['items'])];

    try {
        DB::transaction(function () use ($a) {
            issueNote(NoteRequest::creditAmount($a->id, 1_000, 'goodwill', 'Goodwill'));

            throw new RuntimeException('failed after numbering');
        });
    } catch (RuntimeException) {
    }

    $first = issueNote(NoteRequest::creditAmount($b->id, 1_000, 'goodwill', 'Goodwill'));
    $second = issueNote(NoteRequest::creditAmount($a->id, 1_000, 'goodwill', 'Goodwill'));

    expect([$first->number, $second->number])->toBe(['CN-1', 'CN-2']);
});

test('crediting one line leaves the other creditable, and a line cannot be over-credited (AC 3)', function () {
    billingSettings(['tax.mode' => 'inclusive']);
    $invoice = issueInvoice(noteClient(), [5_000, 6_300])->fresh(['items']);
    [$first, $second] = $invoice->items->pluck('id')->all();

    $note = issueNote(NoteRequest::creditLines($invoice->id, [$second => 6_300], 'lines_cancelled', 'Second line cancelled'));

    expect($note->lines)->toHaveCount(1)->and($note->lines[0]->invoice_item_id)->toBe($second)
        ->and(fn () => issueNote(NoteRequest::creditLines($invoice->id, [$first => 5_001], 'lines_cancelled', 'Too much')))->toThrow(BillingException::class, 'At most');

    expect(issueNote(NoteRequest::creditLines($invoice->id, [$first => 5_000], 'lines_cancelled', 'First line'))->total)->toBe(5_000);
});

test('an old item cancellation after numbering lowers what the invoice and its line can be credited (AC 4)', function () {
    billingSettings(['tax.mode' => 'inclusive']);
    $invoice = issueInvoice(noteClient(), [1_000, 10_300])->fresh(['items']);
    app(CancelInvoiceItems::class)->handle($invoice, [$invoice->items->first()->id]);
    $invoice = $invoice->fresh(['items']);

    expect($invoice)->credited_total->toBe(1_000)
        ->and(fn () => issueNote(NoteRequest::creditAmount($invoice->id, 10_301, 'other', 'Too much')))->toThrow(BillingException::class, 'At most')
        ->and(fn () => issueNote(NoteRequest::creditLines($invoice->id, [$invoice->items->first()->id => 1], 'other', 'Cancelled line')))->toThrow(BillingException::class, 'At most');

    expect(issueNote(NoteRequest::creditAmount($invoice->id, 10_300, 'other', 'Rest'))->total)->toBe(10_300);
});

test('notes by amount, by line and by amount together credit an invoice to the minor unit, per line and per tax level (AC 5)', function () {
    $invoice = issueInvoice(noteClient(), [3_333, 4_444, 1_111])->fresh(['items']);
    [$one, $two, $three] = $invoice->items->pluck('id')->all();

    $notes = [
        issueNote(NoteRequest::creditAmount($invoice->id, 3_001, 'price_correction', 'Part')),
        issueNote(NoteRequest::creditLines($invoice->id, [$three => 777, $one => 100], 'lines_cancelled', 'Lines')),
        issueNote(NoteRequest::creditAmount($invoice->id, $invoice->fresh()->balance, 'invoice_cancelled', 'Rest')),
    ];
    $sums = DB::table('tax_note_lines')->whereIn('tax_note_id', array_map(fn (TaxNote $note) => $note->id, $notes))->groupBy('invoice_item_id')
        ->selectRaw('invoice_item_id as item, sum(amount) as net, sum(tax1_amount) as tax1')->get()->keyBy('item');

    expect($invoice->fresh())->balance->toBe(0)->status->toBe(InvoiceStatus::Cancelled)
        ->and((int) $sums[$one]->net)->toBe(3_333)->and((int) $sums[$two]->net)->toBe(4_444)->and((int) $sums[$three]->net)->toBe(1_111)
        ->and((int) $sums->sum('tax1'))->toBe($invoice->tax1_amount)
        ->and(array_sum(array_map(fn (TaxNote $note) => $note->total, $notes)))->toBe($invoice->total);
    foreach ($invoice->items as $item) {
        expect((int) $sums[$item->id]->tax1)->toBe($item->tax_amount);
    }
});

test('a tax rule changed after numbering does not change a note: it keeps the invoice rates and its report rate (AC 6)', function () {
    $invoice = issueInvoice(Client::factory()->create(['country' => 'US', 'currency' => 'USD']), [10_000])->fresh(['items']);
    TaxRule::query()->update(['rate' => '15.000']);

    $note = issueNote(NoteRequest::creditAmount($invoice->id, 5_650, 'goodwill', 'Half'));

    // Outside Nepal the invoice carries no tax, and its note carries none either.
    expect($note)->taxable_amount->toBe(0)->exempt_amount->toBe(5_650)->tax1_amount->toBe(0)->report_rate->toBe($invoice->report_rate)->report_currency->toBe('NPR');

    TaxRule::query()->update(['rate' => '13.000']);
    $nepal = issueInvoice(noteClient(), [10_000])->fresh(['items']);
    TaxRule::query()->update(['rate' => '15.000']);
    $half = issueNote(NoteRequest::creditAmount($nepal->id, 5_650, 'goodwill', 'Half'));

    expect($half)->taxable_amount->toBe(5_000)->tax1_amount->toBe(650)->report_rate->toBe($nepal->report_rate);
});

test('a note issued at 18:20 UTC on the last day of Asoj is dated 1 Kartik (AC 48)', function () {
    inCompanyTimezone('Asia/Kathmandu');
    CarbonImmutable::setTestNow('2026-10-17 10:00:00 UTC');
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);

    CarbonImmutable::setTestNow('2026-10-17 18:14:59 UTC');
    $before = issueNote(NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'Before midnight'));
    CarbonImmutable::setTestNow('2026-10-17 18:20:00 UTC');
    $after = issueNote(NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'After midnight'));

    expect([$before->bs_issue_date, $before->issue_date->toDateString()])->toBe(['2083-06-31', '2026-10-17'])
        ->and([$after->bs_issue_date, $after->bs_period, $after->issue_date->toDateString()])->toBe(['2083-07-01', '2083-07', '2026-10-18']);
});

test('a confirm is refused when the invoice or the plan changed since the preview (AC 11)', function () {
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);
    $issuer = app(TaxNoteIssuer::class);
    $request = NoteRequest::creditAmount($invoice->id, 5_000, 'goodwill', 'Half');
    $preview = $issuer->preview($request);

    payInvoice($invoice, 1_000);
    expect(fn () => $issuer->issue($request, $preview->version, $preview->digest))->toThrow(StaleNoteException::class, 'The invoice changed; review the note again')
        ->and(TaxNote::query()->count())->toBe(0);

    $fresh = $issuer->preview($request);
    issueNote(NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'Another note'));
    expect(fn () => $issuer->issue($request, $fresh->version, $fresh->digest))->toThrow(StaleNoteException::class)
        ->and(fn () => $issuer->issue($request, $issuer->preview($request)->version, 'not-the-digest'))->toThrow(StaleNoteException::class);
});

test('a note above the open balance is refused until settlement exists, and a pending reservation counts against the caps', function () {
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);
    payInvoice($invoice, 11_300);
    expect(fn () => issueNote(NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'Paid')))->toThrow(BillingException::class, 'needs a settlement');

    $open = issueInvoice(noteClient(), [10_000])->fresh(['items']);
    $refund = DB::table('transactions')->insertGetId(['client_id' => $open->client_id, 'invoice_id' => $open->id, 'gateway' => 'x', 'type' => 'refund', 'status' => 'pending',
        'currency' => 'NPR', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $reservation = DB::table('tax_note_reservations')->insertGetId(['invoice_id' => $open->id, 'transaction_id' => $refund, 'reason_code' => 'refunded', 'digest' => 'a', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('tax_note_reservation_lines')->insert(['reservation_id' => $reservation, 'invoice_item_id' => $open->items[0]->id, 'unit_amount' => 8_000, 'amount' => 8_000, 'tax1_amount' => 1_040, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => issueNote(NoteRequest::creditAmount($open->id, 2_261, 'goodwill', 'Over')))->toThrow(BillingException::class, 'At most')
        ->and(issueNote(NoteRequest::creditAmount($open->id, 2_260, 'goodwill', 'Rest'))->total)->toBe(2_260);
});

test('a debit note adds to the balance with tax from the invoice rates; a note needs a reason, a numbered invoice and no discount line', function () {
    Event::fake([DebitNoteIssued::class]);
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);

    $debit = issueNote(NoteRequest::debit($invoice->id, 565, true, 'late_fee', 'Late payment fee', automatic: true));

    expect($debit)->number->toBe('DN-1')->taxable_amount->toBe(500)->tax1_amount->toBe(65)->total->toBe(565)->automatic->toBeTrue()
        ->and($debit->lines)->toHaveCount(1)->and($debit->lines[0]->invoice_item_id)->toBeNull()
        ->and($invoice->fresh())->balance->toBe(11_865)->debited_total->toBe(565);
    Event::assertDispatched(DebitNoteIssued::class);

    expect(fn () => issueNote(NoteRequest::creditAmount($invoice->id, 100, 'made-up', 'x')))->toThrow(BillingException::class, 'reason')
        ->and(fn () => issueNote(NoteRequest::creditAmount($invoice->id, 100, 'goodwill', ' ')))->toThrow(BillingException::class, 'required')
        ->and(fn () => issueNote(NoteRequest::creditAmount(issueInvoice(noteClient(), [1_000], status: 'draft')->id, 100, 'goodwill', 'Draft')))->toThrow(BillingException::class, 'numbered tax invoice');

    $discounted = issueInvoice(noteClient(), [10_000, -1_000])->fresh();
    expect(fn () => issueNote(NoteRequest::creditAmount($discounted->id, 100, 'goodwill', 'Discount')))->toThrow(BillingException::class, 'discount lines');
});

test('the staff user who issued a note cannot be deleted', function () {
    $user = User::factory()->create();
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);
    $note = issueNote(NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'Goodwill', userId: $user->id));

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->delete()))->toThrow(QueryException::class)
        ->and($note->fresh()->created_by)->toBe($user->id);
});

test('a debit note adds at most the value of the invoice', function () {
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);

    expect(fn () => issueNote(NoteRequest::debit($invoice->id, 11_301, true, 'other', 'Too much')))->toThrow(BillingException::class, 'at most')
        ->and(issueNote(NoteRequest::debit($invoice->id, 11_300, true, 'other', 'Upper bound'))->total)->toBe(11_300);
});

test('a note aimed at one line names it on its adjustment, and a spread over several lines names none', function () {
    $invoice = issueInvoice(noteClient(), [5_000, 6_000])->fresh(['items']);
    $single = issueNote(NoteRequest::creditLines($invoice->id, [$invoice->items[1]->id => 1_000], 'lines_cancelled', 'One line'));
    $spread = issueNote(NoteRequest::creditAmount($invoice->id, 1_000, 'goodwill', 'Spread'));

    expect(DB::table('invoice_adjustments')->where('tax_note_id', $single->id)->value('invoice_item_id'))->toBe($invoice->items[1]->id)
        ->and(DB::table('invoice_adjustments')->where('tax_note_id', $spread->id)->value('invoice_item_id'))->toBeNull();
});

test('credit and debit numbers never collide, even when the stored formats are empty or equal', function (string $credit, string $debit) {
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);
    billingSettings(['credit_note_format' => $credit, 'debit_note_format' => $debit]);

    $numbers = [issueNote(NoteRequest::creditAmount($invoice->id, 100, 'goodwill', 'c'))->number, issueNote(NoteRequest::debit($invoice->id, 100, true, 'other', 'd'))->number];

    expect($numbers[0])->not->toBe($numbers[1])->and($numbers)->toBe(['CN-1', 'DN-1']);
})->with(['both empty' => ['', ''], 'equal' => ['N-{NUMBER}', 'N-{NUMBER}'], 'blank spaces' => ['  ', '  '],
    'the other side falls back to the same format (#396 N1)' => ['DN-{NUMBER}', ''], 'formats that differ only in digits (#396 N1)' => ['A{NUMBER}', 'A1{NUMBER}']]);

test('rolling back the note references is refused while a note exists', function () {
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);
    issueNote(NoteRequest::creditAmount($invoice->id, 100, 'goodwill', 'c'));
    $migration = require base_path('app/Modules/Billing/Database/Migrations/2026_10_25_300700_add_note_references_to_invoice_adjustments.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Rolling back would drop');
});

test('a refused rollback of the note migrations is refused by the newest one first and leaves them all in place (#396 N4)', function (string $file) {
    $invoice = issueInvoice(noteClient(), [10_000])->fresh(['items']);
    $user = User::factory()->create();
    issueNote(NoteRequest::creditAmount($invoice->id, 100, 'goodwill', 'c', userId: $user->id));
    $migration = require base_path("app/Modules/Billing/Database/Migrations/{$file}.php");

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Restore a backup instead.')
        ->and(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->delete()))->toThrow(QueryException::class)
        ->and(Schema::hasColumns('credit_ledger', ['tax_note_id']) && Schema::hasColumns('tax_notes', ['reverses_tax_note_id']))->toBeTrue();
})->with(['2026_10_26_300720_add_note_settlement_and_reversal_columns', '2026_10_25_300710_restrict_deleting_the_issuer_of_a_tax_note']);
