<?php

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\TaxNoteIssuer;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/helpers.php';

/*
| Staff issue, settle and reverse notes (VAT PR 2, vat-register §5.1, §5.2, §5.5; AC 13 to 15, 17). Nepal VAT 13%, NPR,
| Nepal time; the mode is on. An invoice of Rs 10,000 + VAT is Rs 11,300.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR', 'nepal_vat.enabled' => true]);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function staffNoteInvoice(int $paid = 0): Invoice
{
    $invoice = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [1_000_000]);

    if ($paid > 0) {
        payInvoice($invoice, $paid);
    }

    return $invoice->fresh(['items']);
}

/**
 * Preview through the endpoint, then confirm what it returned.
 *
 * @param  array<string, mixed>  $data
 */
function staffConfirmNote(Invoice $invoice, array $data): TestResponse
{
    $preview = test()->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$data]))->assertOk()->json();

    return test()->post(route('admin.billing.invoices.notes.store', $invoice), [...$data, 'version' => $preview['version'], 'digest' => $preview['digest']]);
}

/** @return array<string, mixed> */
function staffFullCredit(string $settlement = 'credit'): array
{
    return ['kind' => 'credit', 'allocation' => 'amount', 'amount' => '11300.00', 'reason_code' => 'service_not_delivered', 'reason_text' => 'Service not delivered', 'settlement' => $settlement];
}

function staffCreditBalance(Invoice $invoice): int
{
    return app(CreditLedgerService::class)->balance($invoice->client_id, 'NPR')->amount;
}

test('a credit note on a paid invoice settled to account credit writes the three rows with its id and leaves the invoice paid (AC 13, 17)', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();
    confirmPassword();

    staffConfirmNote($invoice, staffFullCredit())->assertSessionHasNoErrors();

    $note = TaxNote::query()->sole();
    $rows = DB::table('invoice_adjustments')->where('tax_note_id', $note->id)->orderBy('id')->get(['kind', 'reason', 'amount'])->map(fn ($row) => (array) $row)->all();

    expect($rows)->toBe([['kind' => 'credit', 'reason' => 'credit_note', 'amount' => 1_130_000], ['kind' => 'debit', 'reason' => 'credit_note_to_credit', 'amount' => 1_130_000]])
        ->and(DB::table('credit_ledger')->where('tax_note_id', $note->id)->get(['amount', 'reason'])->map(fn ($row) => (array) $row)->all())->toBe([['amount' => 1_130_000, 'reason' => 'credit_note']])
        ->and($invoice->fresh())->balance->toBe(0)->status->toBe(InvoiceStatus::Paid)->credited_total->toBe(1_130_000)
        ->and(staffCreditBalance($invoice))->toBe(1_130_000)
        ->and(ActivityLog::query()->where('event', 'billing.tax_note.issued')->sole()->properties)->toMatchArray([
            'tax_note_id' => $note->id, 'number' => 'CN-1', 'kind' => 'credit', 'invoice_id' => $invoice->id, 'amount' => 1_130_000, 'currency' => 'NPR',
            'settlement' => 'credit', 'to_credit' => 1_130_000, 'reason_code' => 'service_not_delivered', 'source' => 'staff',
        ])->not->toHaveKey('reason_text');
});

test('a credit note splits between the balance and account credit, and the parts sum to its total', function () {
    $invoice = staffNoteInvoice(paid: 500_000);
    billingStaff();
    confirmPassword();

    $preview = $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...staffFullCredit()]))->assertOk()->json();
    expect($preview)->toMatchArray(['total' => 1_130_000, 'taxable' => 1_000_000, 'tax1' => 130_000, 'to_credit' => 500_000, 'balance_after' => 0, 'settlement' => 'credit']);

    staffConfirmNote($invoice, staffFullCredit())->assertSessionHasNoErrors();

    expect($invoice->fresh())->balance->toBe(0)->status->toBe(InvoiceStatus::Paid)
        ->and(staffCreditBalance($invoice))->toBe(500_000)
        ->and(630_000 + staffCreditBalance($invoice))->toBe(TaxNote::query()->sole()->total);
});

test('above the balance a credit note needs the account credit settlement', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();

    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...staffFullCredit('balance')]))
        ->assertUnprocessable()->assertJsonPath('message', 'A credit note above the open balance of Rs 0.00 needs a settlement: choose account credit.');
});

test('reversing a credit note settled to account credit repeats its lines as a debit note and takes the credit back (AC 14)', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();
    confirmPassword();
    staffConfirmNote($invoice, staffFullCredit());
    $credit = TaxNote::query()->sole();
    app(CreditLedgerService::class)->debit($invoice->client_id, Money::of(400_000, 'NPR'), 'Spent elsewhere');

    staffConfirmNote($invoice, ['reverses' => $credit->id, 'reason_text' => 'Credit note issued by mistake'])->assertSessionHasNoErrors();

    $debit = TaxNote::query()->where('kind', 'debit')->sole();
    $line = fn (TaxNote $note) => $note->lines->map->only(['invoice_item_id', 'amount', 'tax1_amount', 'tax2_amount'])->all();

    expect($debit)->reason_code->toBe('reverses_credit_note')->reverses_tax_note_id->toBe($credit->id)->total->toBe(1_130_000)->tax1_amount->toBe(130_000)
        ->and($line($debit))->toBe($line($credit))
        ->and(DB::table('credit_ledger')->where('tax_note_id', $debit->id)->get(['amount', 'reason'])->map(fn ($row) => (array) $row)->all())->toBe([['amount' => -1_130_000, 'reason' => 'credit_note_reversed']])
        ->and(staffCreditBalance($invoice))->toBe(-400_000)
        ->and($invoice->fresh())->balance->toBe(0)->status->toBe(InvoiceStatus::Paid)->credited_total->toBe(1_130_000)->debited_total->toBe(1_130_000)
        ->and(app(TaxNoteIssuer::class)->creditable($invoice->fresh()))->toBe(1_130_000)
        ->and(app(TaxNoteIssuer::class)->creditableLines($invoice->fresh())[0]['left'])->toBe(1_130_000);
});

test('reversing a note that settled a partly paid invoice makes it owe again', function () {
    $invoice = staffNoteInvoice(paid: 500_000);
    billingStaff();
    confirmPassword();
    staffConfirmNote($invoice, staffFullCredit());

    staffConfirmNote($invoice, ['reverses' => TaxNote::query()->sole()->id, 'reason_text' => 'Mistake'])->assertSessionHasNoErrors();

    expect($invoice->fresh())->balance->toBe(630_000)->status->toBe(InvoiceStatus::Unpaid)->paid_at->toBeNull()
        ->and(staffCreditBalance($invoice))->toBe(0);
});

test('a reversal is refused for a reversing note, a second time, a debit note and a credit note that cancelled the invoice', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();
    confirmPassword();
    staffConfirmNote($invoice, staffFullCredit());
    $credit = TaxNote::query()->sole();
    staffConfirmNote($invoice, ['reverses' => $credit->id, 'reason_text' => 'Mistake']);
    $reversal = TaxNote::query()->where('kind', 'debit')->sole();
    $preview = fn (Invoice $on, int $note) => $this->getJson(route('admin.billing.invoices.notes.preview', [$on, 'reverses' => $note, 'reason_text' => 'Again']))->assertUnprocessable()->json('message');

    $unpaid = staffNoteInvoice();
    staffConfirmNote($unpaid, ['kind' => 'debit', 'amount' => '565.00', 'taxable' => true, 'reason_code' => 'late_fee', 'reason_text' => 'Late fee']);
    $cancelled = staffNoteInvoice();
    staffConfirmNote($cancelled, staffFullCredit('balance'));

    expect($preview($invoice, $reversal->id))->toBe('Debit note DN-1 reverses a credit note and cannot itself be reversed.')
        ->and($preview($invoice, $credit->id))->toBe('Credit note CN-1 is already reversed.')
        ->and($preview($unpaid, TaxNote::query()->where('invoice_id', $unpaid->id)->sole()->id))->toBe('Only a credit note can be reversed; correct debit note DN-2 with a credit note.')
        ->and($cancelled->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($preview($cancelled, TaxNote::query()->where('invoice_id', $cancelled->id)->sole()->id))->toBe('The invoice is cancelled, so it takes no debit note or reversal; raise a new invoice instead.')
        ->and($this->post(route('admin.billing.invoices.notes.store', $invoice), ['kind' => 'debit', 'amount' => '10.00', 'reason_code' => 'reverses_credit_note', 'reason_text' => 'x', 'version' => 1, 'digest' => str_repeat('a', 64)]))
        ->assertSessionHasErrors(['billing' => 'Reverse a credit note from the note itself.']);
});

test('debit notes together add at most the value of the invoice, and a reversal does not count', function () {
    $invoice = staffNoteInvoice();
    billingStaff();
    confirmPassword();
    $debit = fn (string $amount) => staffConfirmNote($invoice, ['kind' => 'debit', 'amount' => $amount, 'taxable' => true, 'reason_code' => 'price_correction', 'reason_text' => 'Price correction']);

    staffConfirmNote($invoice, ['kind' => 'credit', 'allocation' => 'amount', 'amount' => '1000.00', 'reason_code' => 'goodwill', 'reason_text' => 'Goodwill']);
    staffConfirmNote($invoice, ['reverses' => TaxNote::query()->sole()->id, 'reason_text' => 'Mistake'])->assertSessionHasNoErrors();

    $debit('6000.00')->assertSessionHasNoErrors();
    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, 'kind' => 'debit', 'amount' => '5300.01', 'reason_code' => 'other', 'reason_text' => 'x']))
        ->assertUnprocessable()->assertJsonPath('message', 'Debit notes and late fees together add at most the value of the invoice; Rs 5,300.00 is left.');
    $debit('5300.00')->assertSessionHasNoErrors();

    expect($invoice->fresh())->debited_total->toBe(1_230_000)->balance->toBe(2_260_000);
});

test('a confirm that no longer matches its preview is refused and writes nothing', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();
    confirmPassword();
    $preview = $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...staffFullCredit()]))->json();

    $this->post(route('admin.billing.invoices.notes.store', $invoice), [...staffFullCredit(), 'amount' => '11000.00', 'version' => $preview['version'], 'digest' => $preview['digest']])
        ->assertSessionHasErrors(['billing' => 'The invoice changed; review the note again.']);

    expect(TaxNote::query()->count())->toBe(0)->and(DB::table('credit_ledger')->count())->toBe(0);
});

test('without billing.tax_notes.issue every note action is refused and the page offers none (AC 15)', function (array $data) {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    actingAsStaff(['billing.*', 'billing.payments.manage', 'billing.refunds.manage']);
    confirmPassword();

    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$data]))->assertForbidden();
    $this->post(route('admin.billing.invoices.notes.store', $invoice), [...$data, 'version' => 1, 'digest' => str_repeat('a', 64)])->assertForbidden();
    $this->get(route('admin.billing.invoices.show', $invoice))->assertInertia(fn (AssertableInertia $page) => $page->where('can.notes', false)->where('notes_enabled', true));
})->with([
    'issue' => [['kind' => 'debit', 'amount' => '100.00', 'reason_code' => 'other', 'reason_text' => 'x']],
    'settle to credit' => [staffFullCredit()],
    'reverse' => [['reverses' => 1, 'reason_text' => 'x']],
]);

test('with the permission but no recent password the confirm asks for one and writes nothing (AC 15)', function (array $data) {
    $invoice = staffNoteInvoice(paid: 500_000);
    $user = billingStaff();
    confirmPassword();
    staffConfirmNote($invoice, ['kind' => 'credit', 'allocation' => 'amount', 'amount' => '100.00', 'reason_code' => 'goodwill', 'reason_text' => 'Goodwill']);
    $data = isset($data['reverses']) ? [...$data, 'reverses' => TaxNote::query()->sole()->id] : $data;
    $preview = $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$data]))->assertOk()->json();
    $this->actingAs($user)->withSession(['auth.step_up_confirmed_at' => 0]);

    $this->post(route('admin.billing.invoices.notes.store', $invoice), [...$data, 'version' => $preview['version'], 'digest' => $preview['digest']])
        ->assertSessionHasErrors('password_confirmation');

    expect(TaxNote::query()->count())->toBe(1)->and(ActivityLog::query()->where('event', 'billing.tax_note.issued')->count())->toBe(1);
})->with([
    'issue' => [['kind' => 'debit', 'amount' => '100.00', 'reason_code' => 'other', 'reason_text' => 'x']],
    'settle to credit' => [['kind' => 'credit', 'allocation' => 'amount', 'amount' => '7000.00', 'reason_code' => 'goodwill', 'reason_text' => 'x', 'settlement' => 'credit']],
    'reverse' => [['reverses' => true, 'reason_text' => 'x']],
]);

test('the activity entry is written in the issuing transaction: when it fails, no note, number or credit remains', function (array $data) {
    $invoice = staffNoteInvoice(paid: 500_000);
    billingStaff();
    confirmPassword();

    if (isset($data['reverses'])) {
        staffConfirmNote($invoice, staffFullCredit());
        $data['reverses'] = TaxNote::query()->sole()->id;
    }

    $before = [TaxNote::query()->count(), DB::table('credit_ledger')->count(), DB::table('invoice_adjustments')->count(), $invoice->fresh()->version];
    ActivityLog::creating(fn (ActivityLog $log) => $log->event === 'billing.tax_note.issued' ? throw new RuntimeException('activity log down') : null);

    expect(fn () => $this->withoutExceptionHandling()->post(route('admin.billing.invoices.notes.store', $invoice), [...$data,
        ...collect($this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$data]))->json())->only(['version', 'digest'])->all()]))->toThrow(RuntimeException::class, 'activity log down');

    expect([TaxNote::query()->count(), DB::table('credit_ledger')->count(), DB::table('invoice_adjustments')->count(), $invoice->fresh()->version])->toBe($before)
        ->and(DB::table('billing_sequences')->whereIn('sequence', ['credit_note', 'debit_note'])->pluck('next_number', 'sequence')->all())->toBe(isset($data['reverses']) ? ['credit_note' => 2] : []);
})->with([
    'issue' => [['kind' => 'debit', 'amount' => '100.00', 'reason_code' => 'other', 'reason_text' => 'x']],
    'settle to credit' => [staffFullCredit()],
    'reverse' => [['reverses' => true, 'reason_text' => 'x']],
]);

test('while Nepal VAT mode is off staff cannot issue notes', function () {
    billingSettings(['nepal_vat.enabled' => false]);
    $invoice = staffNoteInvoice();
    billingStaff();

    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...staffFullCredit('balance')]))
        ->assertUnprocessable()->assertJsonPath('message', 'Notes can be issued only while Nepal VAT mode is on.');
    $this->get(route('admin.billing.invoices.show', $invoice))->assertInertia(fn (AssertableInertia $page) => $page->where('notes_enabled', false)->where('creditable', null));
});

test('staff who can view invoices see the notes panel with the credit each note posted and its reversal', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();
    confirmPassword();
    staffConfirmNote($invoice, staffFullCredit());
    staffConfirmNote($invoice, ['reverses' => TaxNote::query()->sole()->id, 'reason_text' => 'Mistake']);
    actingAsStaff(['billing.invoices.view', 'billing.tax_notes.view']);

    $this->get(route('admin.billing.invoices.show', $invoice))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('can.notes', false)
        ->where('tax_notes.0', fn ($note) => $note['number'] === 'CN-1' && $note['to_credit'] === 1_130_000 && $note['reversed_by'] === 'DN-1' && $note['bs_issue_date'] === '3 Kartik 2083 BS')
        ->where('tax_notes.1', fn ($note) => $note['number'] === 'DN-1' && $note['reverses'] === 'CN-1' && $note['to_credit'] === 1_130_000)
        ->where('creditable.total', 1_130_000)
        ->where('credit_label', null));

    actingAsStaff(['billing.invoices.view']);
    $this->get(route('admin.billing.invoices.show', $invoice))->assertInertia(fn (AssertableInertia $page) => $page->where('tax_notes', null));
});

test('the invoice is labelled Credited or Partly credited, and the balance card names each note (AC 13)', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();
    confirmPassword();
    staffConfirmNote($invoice, [...staffFullCredit(), 'amount' => '2000.00']);
    $this->get(route('admin.billing.invoices.show', $invoice))->assertInertia(fn (AssertableInertia $page) => $page->where('credit_label', 'Partly credited')
        ->where('invoice.adjustments', fn ($rows) => collect($rows)->pluck('description')->contains('Credit note CN-1')));
    staffConfirmNote($invoice, [...staffFullCredit(), 'amount' => '9300.00']);
    $this->get(route('admin.billing.invoices.show', $invoice))->assertInertia(fn (AssertableInertia $page) => $page->where('credit_label', 'Credited'));
});

test('a debit note on a paid invoice reopens it with a new due date on the normal terms (product finding 3)', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();
    confirmPassword();
    $data = ['kind' => 'debit', 'amount' => '565.00', 'taxable' => true, 'reason_code' => 'price_correction', 'reason_text' => 'Price correction'];

    expect($this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$data]))->json())->toMatchArray(['balance_after' => 56_500, 'due_date' => '2026-11-03']);
    staffConfirmNote($invoice, $data)->assertSessionHasNoErrors();

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Unpaid)->paid_at->toBeNull()->balance->toBe(56_500)
        ->and($invoice->fresh()->due_date->toDateString())->toBe('2026-11-03')
        ->and(ActivityLog::query()->where('event', 'billing.tax_note.issued')->sole()->properties)->toMatchArray(['due_date' => '2026-11-03']);
});

test('the panel says why a note cannot be issued: a discount line, a cancelled invoice', function () {
    $discounted = issueInvoice(Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']), [1_000_000, -100_000]);
    $cancelled = staffNoteInvoice();
    billingStaff();
    confirmPassword();
    staffConfirmNote($cancelled, staffFullCredit('balance'));

    $this->get(route('admin.billing.invoices.show', $discounted))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note_blocked.credit', 'This invoice has a discount line, so no credit note can be issued on it. To correct it, cancel the invoice and raise a new one.')
        ->where('note_blocked.debit', null));
    $this->get(route('admin.billing.invoices.show', $cancelled))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note_blocked.credit', 'The invoice is cancelled; nothing is left to credit.')
        ->where('note_blocked.debit', 'The invoice is cancelled, so it takes no debit note or reversal; raise a new invoice instead.'));
});

test('a reversal preview shows the client account credit after it, below zero when the credit was spent', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();
    confirmPassword();
    staffConfirmNote($invoice, staffFullCredit());
    app(CreditLedgerService::class)->debit($invoice->client_id, Money::of(400_000, 'NPR'), 'Spent elsewhere');

    expect($this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, 'reverses' => TaxNote::query()->sole()->id, 'reason_text' => 'Mistake']))->json())
        ->toMatchArray(['to_credit' => 1_130_000, 'credit_after' => -400_000, 'due_date' => null]);
});

test('field errors name what to enter', function () {
    $invoice = staffNoteInvoice();
    billingStaff();

    $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, 'kind' => 'credit', 'allocation' => 'amount', 'amount' => '1,250', 'reason_text' => '']))
        ->assertUnprocessable()->assertJsonValidationErrors([
            'amount' => 'Enter an amount such as 1,250.00.', 'reason_code' => 'Choose a reason.', 'reason_text' => 'Enter the reason shown to the client.',
        ]);
});

test('a reversal confirmed after the client account credit moved is refused, since the preview showed another credit after', function () {
    $invoice = staffNoteInvoice(paid: 1_130_000);
    billingStaff();
    confirmPassword();
    staffConfirmNote($invoice, staffFullCredit());
    $data = ['reverses' => TaxNote::query()->sole()->id, 'reason_text' => 'Mistake'];
    $preview = $this->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$data]))->json();
    app(CreditLedgerService::class)->debit($invoice->client_id, Money::of(100_000, 'NPR'), 'Spent meanwhile');

    $this->post(route('admin.billing.invoices.notes.store', $invoice), [...$data, 'version' => $preview['version'], 'digest' => $preview['digest']])
        ->assertSessionHasErrors(['billing' => 'The invoice changed; review the note again.']);

    expect(TaxNote::query()->count())->toBe(1);
});
